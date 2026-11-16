<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Domain\Flight\Direction;
use App\Infrastructure\Persistence\Doctrine\DoctrineTimetableDataPurger;
use App\Infrastructure\Persistence\Doctrine\Entity\TimetableFetchRecord;
use App\Tests\Support\ResetsDatabaseSchema;
use App\Tests\Unit\Application\UseCase\SyncTimetableTest;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * The purge command against the test database. TEST ONLY data
 * (SyncTimetableTest::fetched): placeholder flights, commit and reason.
 */
final class PurgeTimetableDataCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ResetsDatabaseSchema;

    // Looked for in the database file after the purge.
    private const FLIGHT_NUMBER = 'ZZ9876';
    private const REASON = 'TEST-ONLY-PURGE-REASON';

    private CommandTester $tester;
    private Connection $connection;

    protected function setUp(): void
    {
        self::mockTime('2026-11-16T03:00:00Z');
        $kernel = self::bootKernel();
        $container = self::getContainer();
        self::resetDatabaseSchema($container);
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();

        $timetables = $container->get('test.timetable_repository');
        foreach (Direction::cases() as $direction) {
            $timetables->save(SyncTimetableTest::fetched($direction, '2026-11-16T02:00:00Z', self::FLIGHT_NUMBER));
        }
        $application = new Application($kernel);
        $gate = new CommandTester($application->find('app:timetable:gate'));
        self::assertSame(Command::SUCCESS, $gate->execute([
            'action' => 'set',
            '--from' => '2026-11-11',
            '--to' => '2027-01-04',
            '--compared-on' => '2026-11-12',
            '--record-commit' => '0123456789abcdef0123456789abcdef01234567',
            '--departure-data-date' => '2026-08-21T13:00:00+09:00',
            '--arrival-data-date' => '2026-08-21T13:00:00+09:00',
        ]));
        self::assertSame(Command::SUCCESS, $gate->execute(['action' => 'withdraw', '--direction' => 'departure', '--reason' => self::REASON]));

        $this->tester = new CommandTester($application->find('app:timetable:purge'));
    }

    protected function tearDown(): void
    {
        // The journal mode is stored in the file, so a WAL test must not leak it.
        $this->connection->executeStatement('PRAGMA journal_mode = DELETE');
        parent::tearDown();
    }

    public function testPurgeDeletesEveryRowAndRewritesTheFile(): void
    {
        self::assertSame('delete', $this->connection->fetchOne('PRAGMA journal_mode'));
        self::assertTrue($this->fileContains(self::FLIGHT_NUMBER), 'Precondition: the flight number is in the file.');

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--confirm' => true]));

        foreach ($this->tables() as $table) {
            self::assertEquals(0, $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table), $table);
        }
        $display = $this->tester->getDisplay();
        self::assertMatchesRegularExpression('/timetable_flight\s+2 rows deleted/', $display);
        self::assertMatchesRegularExpression('/timetable_fetch_record\s+2 rows deleted/', $display);
        self::assertMatchesRegularExpression('/timetable_gate_version\s+1 rows deleted/', $display);
        self::assertMatchesRegularExpression('/timetable_gate_withdrawal\s+1 rows deleted/', $display);
        self::assertMatchesRegularExpression('/timetable_gate_manual_withdrawal\s+1 rows deleted/', $display);
        self::assertStringContainsString('[OK] Every stored timetable and gate row is deleted', $display);

        self::assertFalse($this->fileContains(self::FLIGHT_NUMBER), 'The flight number is still in the database file.');
        self::assertFalse($this->fileContains(self::REASON), 'The withdrawal reason is still in the database file.');
    }

    public function testInWalModeTheMainFileIsCheckpointed(): void
    {
        self::assertSame('wal', $this->connection->fetchOne('PRAGMA journal_mode = WAL'));
        self::assertTrue($this->fileContains(self::FLIGHT_NUMBER), 'Precondition: the flight number is in the file.');

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--confirm' => true]));

        self::assertFalse($this->fileContains(self::FLIGHT_NUMBER), 'The flight number is still in the database file.');
        self::assertFalse($this->fileContains(self::REASON), 'The withdrawal reason is still in the database file.');
        $wal = $this->databasePath().'-wal';
        self::assertTrue(!file_exists($wal) || 0 === filesize($wal), 'The -wal file is not truncated.');
    }

    public function testManagedEntitiesAreDetachedAfterThePurge(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNotNull($entityManager->find(TimetableFetchRecord::class, Direction::Departure));

        $this->tester->execute(['--confirm' => true]);

        // Without clear(), find() would return the managed record without a query.
        self::assertNull($entityManager->find(TimetableFetchRecord::class, Direction::Departure));
    }

    public function testTheRepositoriesReadNothingAfterThePurge(): void
    {
        $this->tester->execute(['--confirm' => true]);

        $container = self::getContainer();
        foreach (Direction::cases() as $direction) {
            self::assertNull($container->get('test.timetable_repository')->findLatest($direction));
        }
        self::assertNull($container->get('test.timetable_gate_repository')->findCurrentState());
    }

    public function testPurgingAnEmptyDatabaseSucceeds(): void
    {
        $this->tester->execute(['--confirm' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--confirm' => true]));
        self::assertMatchesRegularExpression('/timetable_flight\s+0 rows deleted/', $this->tester->getDisplay());
    }

    public function testWithoutConfirmNothingIsDeleted(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute([]));

        self::assertStringContainsString('--confirm', $this->tester->getDisplay());
        self::assertEquals([2, 2, 1, 1, 1], $this->countRows());
    }

    public function testWhileTheLockIsHeldNothingIsDeleted(): void
    {
        // The test environment uses LOCK_DSN=flock, i.e. the system temp directory.
        $other = new LockFactory(new FlockStore())->createLock('tkn-timetable');
        self::assertTrue($other->acquire(false));
        try {
            $status = $this->tester->execute(['--confirm' => true]);
        } finally {
            $other->release();
        }

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('lock is held', $this->tester->getDisplay());
        self::assertEquals([2, 2, 1, 1, 1], $this->countRows());
    }

    public function testAFailedDeletionRollsEverythingBack(): void
    {
        // The last table in the deletion order is gone, so its DELETE fails
        // after the others have run.
        $this->connection->executeStatement('DROP TABLE timetable_fetch_record');

        self::assertSame(Command::FAILURE, $this->tester->execute(['--confirm' => true]));

        self::assertStringContainsString('nothing was deleted', $this->tester->getDisplay());
        foreach (['timetable_flight' => 2, 'timetable_gate_version' => 1, 'timetable_gate_withdrawal' => 1, 'timetable_gate_manual_withdrawal' => 1] as $table => $rows) {
            self::assertEquals($rows, $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table), $table);
        }
        // The entity manager is usable again after the failed transaction.
        self::assertNotNull(self::getContainer()->get('test.timetable_gate_repository')->findCurrentState());
    }

    public function testEveryMappedEntityIsPurged(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $mapped = array_map(static fn ($metadata): string => $metadata->getName(), $entityManager->getMetadataFactory()->getAllMetadata());

        self::assertEqualsCanonicalizing($mapped, DoctrineTimetableDataPurger::ENTITIES);
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return ['timetable_flight', 'timetable_fetch_record', 'timetable_gate_version', 'timetable_gate_withdrawal', 'timetable_gate_manual_withdrawal'];
    }

    /**
     * @return list<mixed> as the driver returns them, so compare with assertEquals
     */
    private function countRows(): array
    {
        return array_map(fn (string $table): mixed => $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table), $this->tables());
    }

    private function fileContains(string $needle): bool
    {
        // Not assertStringContainsString on the file: a failure would print all of it.
        $file = file_get_contents($this->databasePath());
        self::assertIsString($file);

        return str_contains($file, $needle);
    }

    private function databasePath(): string
    {
        $path = $this->connection->getParams()['path'] ?? null;
        self::assertIsString($path);

        return $path;
    }
}
