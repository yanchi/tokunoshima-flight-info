<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Application\Port\TimetableDataPurgerInterface;
use App\Application\Purge\TimetablePurgeResult;
use App\Application\Purge\TimetableSpaceNotReclaimed;
use App\Application\UseCase\PurgeTimetableData;
use App\Command\PurgeTimetableDataCommand;
use App\Tests\Support\StubTimetableLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The outcomes the test database cannot produce.
 */
final class PurgeTimetableDataCommandTest extends TestCase
{
    public function testSpaceNotReclaimedFailsAndListsTheDeletedRows(): void
    {
        $tester = self::tester(new class implements TimetableDataPurgerInterface {
            public function purgeAll(): TimetablePurgeResult
            {
                throw new TimetableSpaceNotReclaimed(['timetable_flight' => 3], new \RuntimeException('secret value'));
            }
        });

        self::assertSame(Command::FAILURE, $tester->execute(['--confirm' => true]));

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/timetable_flight\s+3 rows deleted/', $display);
        self::assertStringContainsString('Run again', $display);
        // The class only, never the driver message.
        self::assertStringContainsString('RuntimeException', $display);
        self::assertStringNotContainsString('secret value', $display);
    }

    public function testADatabaseWithoutVacuumSucceedsWithAWarning(): void
    {
        $tester = self::tester(new class implements TimetableDataPurgerInterface {
            public function purgeAll(): TimetablePurgeResult
            {
                return new TimetablePurgeResult(['timetable_flight' => 0], false);
            }
        });

        self::assertSame(Command::SUCCESS, $tester->execute(['--confirm' => true]));
        self::assertStringContainsString('[WARNING]', $tester->getDisplay());
    }

    private static function tester(TimetableDataPurgerInterface $purger): CommandTester
    {
        return new CommandTester(new PurgeTimetableDataCommand(new PurgeTimetableData($purger, new StubTimetableLock())));
    }
}
