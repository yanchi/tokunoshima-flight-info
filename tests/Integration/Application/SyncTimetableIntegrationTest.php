<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application;

use App\Application\Gate\ComparedItemsHasher;
use App\Application\Gate\ComparedPeriod;
use App\Application\Gate\TimetableGateRequest;
use App\Application\Gate\TimetableGateSettings;
use App\Application\Gate\TimetableGateState;
use App\Application\Gate\TimetableGateVersion;
use App\Application\Gate\WithdrawalCause;
use App\Application\Port\TimetableGateRepositoryInterface;
use App\Application\Port\TimetableRepositoryInterface;
use App\Application\UseCase\SetTimetableGate;
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
    /** @var list<array<string, mixed>> */
    private array $departures;
    /** @var list<array<string, mixed>> */
    private array $arrivals;
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
        $this->departures = self::body(Direction::Departure);
        $this->arrivals = self::body(Direction::Arrival);
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
        self::assertCount(5, $this->latestEntries(Direction::Departure));
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

    public function testSecondIdenticalRunReplacesAndLogsNoChange(): void
    {
        $this->sync(new RecordingLogger())->execute();
        $this->entityManager->clear();
        self::mockTime('2026-11-12T03:10:00Z');

        $logger = new RecordingLogger();
        $report = $this->sync($logger)->execute();

        self::assertTrue($report->isSuccessful());
        // Really replaced, so the stored data went through the comparison.
        self::assertTrue($logger->has('info', 'Timetable saved', 'departure'), implode("\n", $logger->lines()));
        self::assertSame('2026-11-12T03:10:00+00:00', $this->repository->findLatest(Direction::Departure)?->requestStartedAt->format(\DATE_ATOM));
        self::assertFalse($logger->has('notice', 'changed'), implode("\n", $logger->lines()));
    }

    public function testChangedResponseIsLoggedOnTheNextRun(): void
    {
        $this->sync(new RecordingLogger())->execute();
        $this->entityManager->clear();
        self::mockTime('2026-11-12T03:10:00Z');
        $this->departures = [FictionalOdptTimetable::record('TKN', 'ZZZ', 'Monday', [
            FictionalOdptTimetable::flight('ZZ1001', '09:05', '09:45'),
            FictionalOdptTimetable::flight('ZZ1003', '15:00', '15:40', ['ja' => 'テスト用の備考']),
        ])];

        $logger = new RecordingLogger();
        $this->sync($logger)->execute();

        self::assertTrue($logger->has('notice', 'content changed', 'departure'));
        self::assertFalse($logger->has('notice', 'content changed', 'arrival'));
    }

    /**
     * The gate command's version is set from the database, and the next
     * sync compares the changed response with it (§7 step 7, comparison (a)).
     */
    public function testChangeInsideTheComparedPeriodIsWithdrawnInTheDatabase(): void
    {
        $this->sync(new RecordingLogger())->execute();
        $this->entityManager->clear();
        $this->setGateFromTheDatabase();

        self::mockTime('2026-11-12T03:10:00Z');
        $this->departures[0] = FictionalOdptTimetable::record('TKN', 'ZZZ', 'Monday', [
            FictionalOdptTimetable::flight('ZZ1001', '09:05', '09:40'),
            FictionalOdptTimetable::flight('ZZ1003', '15:00', '15:40', ['ja' => 'テスト用の備考']),
        ]);
        $logger = new RecordingLogger();
        $this->sync($logger)->execute();
        $this->entityManager->clear();

        $state = self::getContainer()->get('test.timetable_gate_repository')->findCurrentState();
        self::assertSame([Direction::Departure], $state?->withdrawn, implode("\n", $logger->lines()));
        self::assertTrue($logger->has('warning', 'withdrawn from publication', 'departure'));
    }

    /**
     * Right after the version is set, an unchanged response must neither
     * withdraw a direction nor be logged as (b): otherwise both directions
     * would drop out on the first sync after a deploy.
     */
    public function testUnchangedResponseAfterSettingTheGateKeepsBothDirections(): void
    {
        $this->sync(new RecordingLogger())->execute();
        $this->entityManager->clear();
        $this->setGateFromTheDatabase();

        self::mockTime('2026-11-12T03:10:00Z');
        $logger = new RecordingLogger();
        $report = $this->sync($logger)->execute();
        $this->entityManager->clear();

        self::assertTrue($report->isSuccessful(), implode("\n", $logger->lines()));
        self::assertTrue($logger->has('info', 'Timetable saved', 'departure'));
        self::assertSame([], self::getContainer()->get('test.timetable_gate_repository')->findCurrentState()?->withdrawn);
        self::assertFalse($logger->has('warning', 'withdrawn'), implode("\n", $logger->lines()));
        self::assertFalse($logger->has('notice', 'changed'), implode("\n", $logger->lines()));
    }

    /**
     * A failure after comparison (a) withdrew the direction rolls the
     * withdrawal back with the timetable (§7 step 7 「同期のトランザクション」).
     */
    public function testFailureAfterTheWithdrawalRollsBothBack(): void
    {
        $this->sync(new RecordingLogger())->execute();
        $this->entityManager->clear();
        $this->setGateFromTheDatabase();
        $stored = $this->repository->findLatest(Direction::Departure)?->requestStartedAt;

        self::mockTime('2026-11-12T03:10:00Z');
        $this->departures[0] = FictionalOdptTimetable::record('TKN', 'ZZZ', 'Monday', [
            FictionalOdptTimetable::flight('ZZ1001', '09:05', '09:40'),
            FictionalOdptTimetable::flight('ZZ1003', '15:00', '15:40', ['ja' => 'テスト用の備考']),
        ]);
        $inner = self::getContainer()->get('test.timetable_gate_repository');
        $failing = new class($inner) implements TimetableGateRepositoryInterface {
            public function __construct(private readonly TimetableGateRepositoryInterface $inner)
            {
            }

            public function transactional(callable $callback): mixed
            {
                return $this->inner->transactional($callback);
            }

            public function findCurrentState(): ?TimetableGateState
            {
                return $this->inner->findCurrentState();
            }

            public function addVersion(TimetableGateSettings $settings, \DateTimeImmutable $setAt): TimetableGateVersion
            {
                return $this->inner->addVersion($settings, $setAt);
            }

            public function withdraw(int $versionId, Direction $direction, WithdrawalCause $cause, \DateTimeImmutable $withdrawnAt): bool
            {
                $this->inner->withdraw($versionId, $direction, $cause, $withdrawnAt);
                throw new \RuntimeException('TEST ONLY: fails after the withdrawal is written');
            }

            public function recordManualWithdrawal(int $versionId, Direction $direction, string $reason, \DateTimeImmutable $executedAt): void
            {
                $this->inner->recordManualWithdrawal($versionId, $direction, $reason, $executedAt);
            }
        };
        $logger = new RecordingLogger();
        $report = $this->sync($logger, $failing)->execute();
        $this->entityManager->clear();

        self::assertSame([Direction::Departure], $report->notSaved);
        self::assertTrue($logger->has('error', 'Timetable save failed', 'departure'));
        $gate = self::getContainer()->get('test.timetable_gate_repository');
        self::assertSame([], $gate->findCurrentState()?->withdrawn);
        self::assertEquals($stored, $this->repository->findLatest(Direction::Departure)?->requestStartedAt);
    }

    /**
     * Both directions withdrawn in one run: the second direction's
     * transaction reads and writes after the first one's withdrawal.
     */
    public function testBothDirectionsCanBeWithdrawnInOneRun(): void
    {
        $this->sync(new RecordingLogger())->execute();
        $this->entityManager->clear();
        $this->setGateFromTheDatabase();

        self::mockTime('2026-11-12T03:10:00Z');
        $this->departures[0] = FictionalOdptTimetable::record('TKN', 'ZZZ', 'Monday', [
            FictionalOdptTimetable::flight('ZZ1001', '09:05', '09:40'),
            FictionalOdptTimetable::flight('ZZ1003', '15:00', '15:40', ['ja' => 'テスト用の備考']),
        ]);
        $this->arrivals = [FictionalOdptTimetable::record('ZZZ', 'TKN', 'Monday', [FictionalOdptTimetable::flight('ZZ1002', '10:35', '11:10')])];
        $logger = new RecordingLogger();
        $report = $this->sync($logger)->execute();
        $this->entityManager->clear();

        self::assertTrue($report->isSuccessful(), implode("\n", $logger->lines()));
        $state = self::getContainer()->get('test.timetable_gate_repository')->findCurrentState();
        self::assertEquals([Direction::Departure, Direction::Arrival], $state?->withdrawn);
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

    private function setGateFromTheDatabase(): void
    {
        $setGate = new SetTimetableGate(
            $this->repository,
            self::getContainer()->get('test.timetable_gate_repository'),
            new SymfonyTimetableLock(new LockFactory(new FlockStore($this->lockDirectory)), new RecordingLogger()),
            new ComparedItemsHasher(),
            self::getContainer()->get('test.clock'),
        );
        $dataDates = array_map(fn (Direction $d): string => $this->repository->findLatest($d)?->latestDataDate->format(\DATE_ATOM) ?? '', Direction::cases());
        $setGate->execute(TimetableGateRequest::fromStrings('2026-11-11', '2027-01-04', '2026-11-12', str_repeat('a', 40), $dataDates[0], $dataDates[1]));
    }

    private function sync(RecordingLogger $logger, ?TimetableGateRepositoryInterface $gate = null): SyncTimetable
    {
        $departures = $this->departures;
        $arrivals = $this->arrivals;
        $http = new MockHttpClient(static function (string $method, string $url) use ($departures, $arrivals): MockResponse {
            $body = str_contains($url, 'originAirport=odpt.Airport:TKN') ? $departures : $arrivals;

            return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR));
        });
        // The global clock moved by mockTime(), shared with the use case and
        // the repository, so a later run really starts later.
        $clock = self::getContainer()->get('test.clock');
        $provider = new OdptTimetableProvider(
            new OdptTimetableClient($http, 'test-consumer-key', $logger, $clock, 0),
            new OdptTimetableMapper(),
            $clock,
        );

        return new SyncTimetable(
            $provider,
            $this->repository,
            new SymfonyTimetableLock(new LockFactory(new FlockStore($this->lockDirectory)), $logger),
            self::getContainer()->get('test.clock'),
            new TimetableFreshnessPolicy('259200', '604800'),
            new ComparedItemsHasher(),
            $gate ?? self::getContainer()->get('test.timetable_gate_repository'),
            $logger,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function body(Direction $direction): array
    {
        if (Direction::Arrival === $direction) {
            return [FictionalOdptTimetable::arrivalRecord()];
        }

        // A note, a missing and a zero day difference, and one flight in two
        // consecutive periods, so every kind of value makes the round trip.
        $zeroDays = FictionalOdptTimetable::flight('ZZ1005', '11:00', '11:40');
        $zeroDays['odpt:originDayDifference'] = 0;
        $zeroDays['odpt:destinationDayDifference'] = 0;
        $firstPeriod = FictionalOdptTimetable::flight('ZZ1007', '12:00', '12:40');
        $firstPeriod['odpt:isValidTo'] = '2026-11-30T23:59:59+09:00';
        $secondPeriod = FictionalOdptTimetable::flight('ZZ1007', '12:10', '12:50', ['ja' => 'テスト用の別の備考']);
        $secondPeriod['odpt:isValidFrom'] = '2026-12-01T00:00:00+09:00';

        return [
            FictionalOdptTimetable::departureRecord(),
            FictionalOdptTimetable::record('TKN', 'ZZZ', 'Tuesday', [$zeroDays, $firstPeriod, $secondPeriod]),
        ];
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
