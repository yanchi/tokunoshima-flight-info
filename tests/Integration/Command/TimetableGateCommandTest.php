<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Application\Gate\ComparedItemsHasher;
use App\Application\Port\TimetableGateRepositoryInterface;
use App\Application\Port\TimetableRepositoryInterface;
use App\Domain\Flight\Direction;
use App\Tests\Unit\Application\UseCase\SyncTimetableTest;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * The gate command against the test database. TEST ONLY data
 * (SyncTimetableTest::fetched): placeholder flights and commit.
 */
final class TimetableGateCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const COMMIT = '0123456789abcdef0123456789abcdef01234567';

    private CommandTester $tester;
    private TimetableGateRepositoryInterface $gate;
    private TimetableRepositoryInterface $timetables;

    protected function setUp(): void
    {
        self::mockTime('2026-11-16T03:00:00Z');
        $kernel = self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->gate = self::getContainer()->get('test.timetable_gate_repository');
        $this->timetables = self::getContainer()->get('test.timetable_repository');
        foreach (Direction::cases() as $direction) {
            $this->timetables->save(SyncTimetableTest::fetched($direction, '2026-11-16T02:00:00Z'));
        }
        $this->tester = new CommandTester(new Application($kernel)->find('app:timetable:gate'));
    }

    public function testSetStoresAVersionAndPrintsTheHashes(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester->execute(self::setInput()));

        $state = $this->gate->findCurrentState();
        self::assertNotNull($state);
        $departures = $this->timetables->findLatest(Direction::Departure);
        self::assertNotNull($departures);
        $hash = new ComparedItemsHasher()->hash($state->version->settings->period, $departures->entries);
        self::assertSame($hash, $state->version->settings->departureHash);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString($hash, $display);
        self::assertStringContainsString($state->version->settings->arrivalHash, $display);
        self::assertStringContainsString('2026-11-11 to 2027-01-04', $display);
    }

    public function testSetWithAMismatchingDataDateChangesNothing(): void
    {
        $status = $this->tester->execute(self::setInput(['--arrival-data-date' => '2026-08-21T13:00:01+09:00']));

        self::assertSame(Command::FAILURE, $status);
        self::assertNull($this->gate->findCurrentState());
        self::assertStringContainsString('arrival dc:date does not match', $this->tester->getDisplay());
    }

    public function testSetWithAMissingInputChangesNothing(): void
    {
        $input = self::setInput();
        unset($input['--record-commit']);

        self::assertSame(Command::FAILURE, $this->tester->execute($input));
        self::assertNull($this->gate->findCurrentState());
    }

    public function testSetWhileTheLockIsHeldChangesNothing(): void
    {
        // The test environment uses LOCK_DSN=flock, i.e. the system temp directory.
        $other = new LockFactory(new FlockStore())->createLock('tkn-timetable');
        self::assertTrue($other->acquire(false));
        try {
            $status = $this->tester->execute(self::setInput());
        } finally {
            $other->release();
        }

        self::assertSame(Command::FAILURE, $status);
        self::assertNull($this->gate->findCurrentState());
        self::assertStringContainsString('lock is held', $this->tester->getDisplay());
    }

    public function testWithdrawAfterSetWithdrawsOnlyThatDirection(): void
    {
        $this->tester->execute(self::setInput());

        $status = $this->tester->execute(['action' => 'withdraw', '--direction' => 'departure', '--reason' => 'テスト用の理由']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([Direction::Departure], $this->gate->findCurrentState()?->withdrawn);
    }

    public function testWithdrawBeforeAnyVersionFails(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute(['action' => 'withdraw', '--direction' => 'arrival', '--reason' => 'test']));
        self::assertNull($this->gate->findCurrentState());
    }

    public function testWithdrawWithoutADirectionOrAReasonFails(): void
    {
        $this->tester->execute(self::setInput());

        self::assertSame(Command::FAILURE, $this->tester->execute(['action' => 'withdraw', '--reason' => 'test']));
        self::assertSame(Command::FAILURE, $this->tester->execute(['action' => 'withdraw', '--direction' => 'both', '--reason' => 'test']));
        self::assertSame(Command::FAILURE, $this->tester->execute(['action' => 'withdraw', '--direction' => 'arrival']));
        self::assertSame(Command::FAILURE, $this->tester->execute(['action' => 'withdraw', '--direction' => 'arrival', '--reason' => '  ']));
        self::assertSame([], $this->gate->findCurrentState()?->withdrawn);
    }

    public function testUnknownActionFails(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute(['action' => 'release']));
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private static function setInput(array $override = []): array
    {
        return $override + [
            'action' => 'set',
            '--from' => '2026-11-11',
            '--to' => '2027-01-04',
            '--compared-on' => '2026-11-12',
            '--record-commit' => self::COMMIT,
            '--departure-data-date' => '2026-08-21T13:00:00+09:00',
            '--arrival-data-date' => '2026-08-21T13:00:00+09:00',
        ];
    }
}
