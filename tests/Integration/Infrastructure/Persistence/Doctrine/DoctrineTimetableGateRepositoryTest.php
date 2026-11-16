<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence\Doctrine;

use App\Application\DTO\TimetableFetchResult;
use App\Application\Gate\ComparedPeriod;
use App\Application\Gate\TimetableGateSettings;
use App\Application\Gate\WithdrawalCause;
use App\Application\Port\TimetableGateRepositoryInterface;
use App\Application\Port\TimetableRepositoryInterface;
use App\Domain\Flight\Direction;
use App\Domain\Flight\ServiceDate;
use App\Tests\Unit\Application\UseCase\SyncTimetableTest;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * TEST ONLY data: placeholder hashes, commit and flights.
 */
final class DoctrineTimetableGateRepositoryTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private TimetableGateRepositoryInterface $gate;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::mockTime('2026-11-16T03:00:00Z');
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->gate = $container->get('test.timetable_gate_repository');
    }

    public function testNoStateBeforeTheFirstVersion(): void
    {
        self::assertNull($this->gate->findCurrentState());
    }

    public function testVersionIsReadBackUnchanged(): void
    {
        $settings = self::settings('0');
        $added = $this->gate->addVersion($settings, new \DateTimeImmutable('2026-11-16T12:00:00+09:00'));
        $this->entityManager->clear();

        $state = $this->gate->findCurrentState();
        self::assertNotNull($state);
        self::assertSame($added->id, $state->version->id);
        $read = $state->version->settings;
        self::assertSame('2026-11-11', $read->period->from->toString());
        self::assertSame('2027-01-04', $read->period->to->toString());
        self::assertSame('2026-11-12', $read->comparedOn->toString());
        self::assertSame($settings->recordCommit, $read->recordCommit);
        // Instants survive; the offset is not kept.
        self::assertEquals($settings->departureDataDate, $read->departureDataDate);
        self::assertEquals($settings->arrivalDataDate, $read->arrivalDataDate);
        self::assertSame($settings->departureHash, $read->departureHash);
        self::assertSame($settings->arrivalHash, $read->arrivalHash);
        self::assertEquals(new \DateTimeImmutable('2026-11-16T03:00:00Z'), $state->version->setAt);
        self::assertSame([], $state->withdrawn);
    }

    public function testLatestVersionIsInForceAndStartsWithoutWithdrawals(): void
    {
        $first = $this->gate->addVersion(self::settings('0'), new \DateTimeImmutable());
        self::assertTrue($this->gate->withdraw($first->id, Direction::Departure, WithdrawalCause::ComparedItemsChanged, new \DateTimeImmutable()));
        $second = $this->gate->addVersion(self::settings('1'), new \DateTimeImmutable());
        $this->entityManager->clear();

        $state = $this->gate->findCurrentState();
        self::assertSame($second->id, $state?->version->id);
        self::assertSame(str_repeat('1', 64), $state->version->settings->departureHash);
        self::assertSame([], $state->withdrawn);
    }

    public function testWithdrawalIsKeptOncePerVersionAndDirection(): void
    {
        $version = $this->gate->addVersion(self::settings('0'), new \DateTimeImmutable());

        self::assertTrue($this->gate->withdraw($version->id, Direction::Arrival, WithdrawalCause::Manual, new \DateTimeImmutable()));
        $this->entityManager->clear();
        self::assertFalse($this->gate->withdraw($version->id, Direction::Arrival, WithdrawalCause::ComparedItemsChanged, new \DateTimeImmutable()));
        self::assertTrue($this->gate->withdraw($version->id, Direction::Departure, WithdrawalCause::ComparedItemsChanged, new \DateTimeImmutable()));

        self::assertEquals([Direction::Arrival, Direction::Departure], $this->gate->findCurrentState()?->withdrawn);
        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT direction, cause FROM timetable_gate_withdrawal ORDER BY id');
        self::assertSame([
            ['direction' => 'arrival', 'cause' => 'manual'],
            ['direction' => 'departure', 'cause' => 'compared_items_changed'],
        ], $rows);
    }

    public function testEveryManualOperationIsRecorded(): void
    {
        $version = $this->gate->addVersion(self::settings('0'), new \DateTimeImmutable());

        $this->gate->recordManualWithdrawal($version->id, Direction::Departure, 'テスト用の理由1', new \DateTimeImmutable('2026-11-16T03:00:00Z'));
        $this->gate->recordManualWithdrawal($version->id, Direction::Departure, 'テスト用の理由2', new \DateTimeImmutable('2026-11-16T04:00:00Z'));

        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT version_id, direction, reason, executed_at FROM timetable_gate_manual_withdrawal ORDER BY id');
        self::assertSame([
            ['version_id' => $version->id, 'direction' => 'departure', 'reason' => 'テスト用の理由1', 'executed_at' => '2026-11-16 03:00:00'],
            ['version_id' => $version->id, 'direction' => 'departure', 'reason' => 'テスト用の理由2', 'executed_at' => '2026-11-16 04:00:00'],
        ], $rows);
    }

    public function testOperationOnAMissingVersionFails(): void
    {
        $this->expectException(\LogicException::class);

        $this->gate->recordManualWithdrawal(42, Direction::Departure, 'test', new \DateTimeImmutable());
    }

    public function testFailureInsideTheTransactionStoresNothing(): void
    {
        try {
            $this->gate->transactional(function (): void {
                $this->gate->addVersion(self::settings('0'), new \DateTimeImmutable());
                throw new \RuntimeException('test');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('test', $e->getMessage());
        }

        self::assertNull($this->gate->findCurrentState());
    }

    /**
     * The sync records (a) from the timetable save's callback: a failure
     * after it must roll the withdrawal back with the timetable (§7 step 7
     * 「同期のトランザクション」).
     */
    public function testWithdrawalFromTheSaveCallbackRollsBackWithTheSave(): void
    {
        /** @var TimetableRepositoryInterface $timetables */
        $timetables = self::getContainer()->get('test.timetable_repository');
        $timetables->save(SyncTimetableTest::fetched(Direction::Departure, '2026-11-16T02:00:00Z'));
        $version = $this->gate->addVersion(self::settings('0'), new \DateTimeImmutable());

        try {
            $timetables->save(
                SyncTimetableTest::fetched(Direction::Departure, '2026-11-16T02:30:00Z', flightNumber: 'ZZ1005'),
                function (?TimetableFetchResult $previous) use ($version): void {
                    $this->gate->withdraw($version->id, Direction::Departure, WithdrawalCause::ComparedItemsChanged, new \DateTimeImmutable());
                    throw new \RuntimeException('test');
                },
            );
            self::fail('The failure was swallowed.');
        } catch (\RuntimeException) {
        }

        $gate = self::getContainer()->get('test.timetable_gate_repository');
        self::assertSame([], $gate->findCurrentState()?->withdrawn);
        self::assertSame('ZZ1001', $timetables->findLatest(Direction::Departure)?->entries[0]->flightNumber->value);
    }

    private static function settings(string $hashDigit): TimetableGateSettings
    {
        return new TimetableGateSettings(
            new ComparedPeriod(ServiceDate::fromString('2026-11-11'), ServiceDate::fromString('2027-01-04')),
            ServiceDate::fromString('2026-11-12'),
            '0123456789abcdef0123456789abcdef01234567',
            new \DateTimeImmutable('2026-08-21T13:00:00+09:00'),
            new \DateTimeImmutable('2026-08-21T14:00:00+09:00'),
            str_repeat($hashDigit, 64),
            str_repeat('f', 64),
        );
    }
}
