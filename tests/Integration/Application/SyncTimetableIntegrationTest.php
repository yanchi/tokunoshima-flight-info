<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application;

use App\Application\Gate\ComparedItemsHasher;
use App\Application\Gate\ComparedPeriod;
use App\Application\Port\TimetableRepositoryInterface;
use App\Application\UseCase\SyncTimetable;
use App\Domain\Flight\Direction;
use App\Domain\Flight\ServiceDate;
use App\Domain\Flight\TimetableFreshnessPolicy;
use App\Infrastructure\Lock\SymfonyTimetableLock;
use App\Infrastructure\Provider\Odpt\OdptTimetableClient;
use App\Infrastructure\Provider\Odpt\OdptTimetableMapper;
use App\Infrastructure\Provider\Odpt\OdptTimetableProvider;
use App\Tests\Support\FictionalOdptTimetable;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * The sync from a mocked ODPT response to the database. Never reaches the
 * real API; the responses are fictional (FictionalOdptTimetable).
 */
final class SyncTimetableIntegrationTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const NOW = '2026-11-12T03:00:00Z';

    private TimetableRepositoryInterface $repository;
    private EntityManagerInterface $entityManager;
    private string $lockDirectory;

    protected function setUp(): void
    {
        self::mockTime(self::NOW);
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->repository = $container->get('test.timetable_repository');
        $this->lockDirectory = sys_get_temp_dir().'/tkn-lock-test-'.bin2hex(random_bytes(4));
        mkdir($this->lockDirectory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->lockDirectory.'/*') ?: []);
        rmdir($this->lockDirectory);
        parent::tearDown();
    }

    public function testSavesBothDirectionsFromTheResponse(): void
    {
        $report = $this->sync(new RecordingLogger())->execute();

        self::assertTrue($report->isSuccessful());
        self::assertCount(2, $this->latestEntries(Direction::Departure));
        self::assertCount(1, $this->latestEntries(Direction::Arrival));
    }

    /**
     * The value the gate command will hash from the database must be the
     * one hashed right after the import (§7 step 7).
     */
    public function testHashOfTheStoredTimetableEqualsTheHashOfTheImportedOne(): void
    {
        $this->sync(new RecordingLogger())->execute();

        $period = new ComparedPeriod(ServiceDate::fromString('2026-11-11'), ServiceDate::fromString('2027-01-04'));
        $hasher = new ComparedItemsHasher();
        foreach (Direction::cases() as $direction) {
            $imported = new OdptTimetableMapper()->map($direction, self::body($direction), new \DateTimeImmutable(self::NOW));
            $this->entityManager->clear();

            self::assertSame(
                $hasher->hash($period, $imported->entries),
                $hasher->hash($period, $this->latestEntries($direction)),
                $direction->value,
            );
        }
    }

    public function testSecondIdenticalRunLogsNoChange(): void
    {
        $this->sync(new RecordingLogger())->execute();
        $this->entityManager->clear();
        self::mockTime('2026-11-12T03:10:00Z');

        $logger = new RecordingLogger();
        $report = $this->sync($logger)->execute();

        self::assertTrue($report->isSuccessful());
        self::assertFalse($logger->has('notice', 'changed'), implode("\n", $logger->lines()));
    }

    public function testHeldLockSkipsBothDirections(): void
    {
        $other = new LockFactory(new FlockStore($this->lockDirectory))->createLock('tkn-timetable');
        self::assertTrue($other->acquire(false));

        try {
            $logger = new RecordingLogger();
            $report = $this->sync($logger)->execute();
        } finally {
            $other->release();
        }

        self::assertSame([Direction::Departure, Direction::Arrival], $report->notSaved);
        self::assertTrue($logger->has('error', 'skipped'));
        self::assertNull($this->repository->findLatest(Direction::Departure));
    }

    private function sync(RecordingLogger $logger): SyncTimetable
    {
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            $direction = str_contains($url, 'originAirport=odpt.Airport:TKN') ? Direction::Departure : Direction::Arrival;

            return new MockResponse(json_encode(self::body($direction), \JSON_THROW_ON_ERROR));
        });
        $clock = new MockClock(self::NOW);
        $provider = new OdptTimetableProvider(
            new OdptTimetableClient($http, 'test-consumer-key', $logger, $clock, 0),
            new OdptTimetableMapper(),
            $clock,
        );

        return new SyncTimetable(
            $provider,
            $this->repository,
            new SymfonyTimetableLock(new LockFactory(new FlockStore($this->lockDirectory))),
            self::getContainer()->get('test.clock'),
            new TimetableFreshnessPolicy('259200', '604800'),
            new ComparedItemsHasher(),
            $logger,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function body(Direction $direction): array
    {
        return Direction::Departure === $direction
            ? [FictionalOdptTimetable::departureRecord()]
            : [FictionalOdptTimetable::arrivalRecord()];
    }

    /**
     * @return list<\App\Application\DTO\TimetableEntry>
     */
    private function latestEntries(Direction $direction): array
    {
        $found = $this->repository->findLatest($direction);
        self::assertNotNull($found);

        return $found->entries;
    }
}
