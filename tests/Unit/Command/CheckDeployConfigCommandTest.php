<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CheckDeployConfigCommand;
use App\Domain\Flight\TimetableFreshnessPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckDeployConfigCommandTest extends TestCase
{
    public function testPassesWhenAllSettingsAreSet(): void
    {
        $tester = new CommandTester(self::command('prod', 'test-secret'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonProdEnvironments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'test' => ['test'];
    }

    #[DataProvider('nonProdEnvironments')]
    public function testFailsOutsideProd(string $environment): void
    {
        $tester = new CommandTester(self::command($environment, 'test-secret'));

        self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('APP_ENV=prod', $tester->getErrorOutput());
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function badSettings(): iterable
    {
        yield 'secret empty' => ['', 'to@example.invalid', 'from@example.invalid', 'smtp://u:p@mail.invalid:587', 'APP_SECRET'];
        yield 'secret blank' => ['  ', 'to@example.invalid', 'from@example.invalid', 'smtp://u:p@mail.invalid:587', 'APP_SECRET'];
        yield 'recipient empty' => ['test-secret', '', 'from@example.invalid', 'smtp://u:p@mail.invalid:587', 'CONTACT_RECIPIENT'];
        yield 'recipient blank' => ['test-secret', '  ', 'from@example.invalid', 'smtp://u:p@mail.invalid:587', 'CONTACT_RECIPIENT'];
        yield 'from empty' => ['test-secret', 'to@example.invalid', '', 'smtp://u:p@mail.invalid:587', 'CONTACT_FROM'];
        yield 'dsn empty' => ['test-secret', 'to@example.invalid', 'from@example.invalid', '', 'MAILER_DSN'];
        yield 'dsn null transport' => ['test-secret', 'to@example.invalid', 'from@example.invalid', 'null://null', 'MAILER_DSN'];
        yield 'dsn native transport' => ['test-secret', 'to@example.invalid', 'from@example.invalid', 'native://default', 'MAILER_DSN'];
        yield 'all empty' => ['', '', '', '', 'APP_SECRET, CONTACT_RECIPIENT, CONTACT_FROM, MAILER_DSN'];
    }

    #[DataProvider('badSettings')]
    public function testFailsAndNamesBadSettings(string $secret, string $recipient, string $from, string $dsn, string $expected): void
    {
        $tester = new CommandTester(new CheckDeployConfigCommand('prod', $secret, $recipient, $from, $dsn, self::validThresholds()));

        self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Unset or invalid settings (empty, placeholder or out of range): '.$expected, $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
    }

    public function testDoesNotPrintValues(): void
    {
        $tester = new CommandTester(new CheckDeployConfigCommand('prod', 'do-not-print-this', '', '', 'smtp://user:do-not-print-this@mail.invalid:587', self::validThresholds()));
        $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertStringNotContainsString('do-not-print-this', $tester->getDisplay().$tester->getErrorOutput());
    }

    public function testFailsAndNamesInvalidTimetableThresholds(): void
    {
        $tester = new CommandTester(new CheckDeployConfigCommand('prod', 'test-secret', 'to@example.invalid', 'from@example.invalid', 'smtp://u:p@mail.invalid:587', new TimetableFreshnessPolicy('604800', '259200')));

        self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('TIMETABLE_WARN_AFTER_SECONDS / TIMETABLE_SUSPEND_AFTER_SECONDS', $tester->getErrorOutput());
    }

    private static function command(string $environment, string $secret): CheckDeployConfigCommand
    {
        return new CheckDeployConfigCommand($environment, $secret, 'to@example.invalid', 'from@example.invalid', 'smtp://u:p@mail.invalid:587', self::validThresholds());
    }

    private static function validThresholds(): TimetableFreshnessPolicy
    {
        return new TimetableFreshnessPolicy('259200', '604800');
    }
}
