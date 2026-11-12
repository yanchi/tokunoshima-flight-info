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
        $tester = new CommandTester(new CheckDeployConfigCommand('prod', 'test-secret', 'test@example.invalid', self::validThresholds()));

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
        $tester = new CommandTester(new CheckDeployConfigCommand($environment, 'test-secret', 'test@example.invalid', self::validThresholds()));

        self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('APP_ENV=prod', $tester->getErrorOutput());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function emptySettings(): iterable
    {
        yield 'secret empty' => ['', 'test@example.invalid', 'APP_SECRET'];
        yield 'secret blank' => ['  ', 'test@example.invalid', 'APP_SECRET'];
        yield 'contact empty' => ['test-secret', '', 'CONTACT_ADDRESS'];
        yield 'contact blank' => ['test-secret', '  ', 'CONTACT_ADDRESS'];
        yield 'contact placeholder' => ['test-secret', '〇〇', 'CONTACT_ADDRESS'];
        yield 'contact placeholder in text' => ['test-secret', 'お問い合わせは〇〇まで', 'CONTACT_ADDRESS'];
        yield 'contact look-alike placeholder' => ['test-secret', '○○', 'CONTACT_ADDRESS'];
        yield 'both empty' => ['', '', 'APP_SECRET, CONTACT_ADDRESS'];
    }

    #[DataProvider('emptySettings')]
    public function testFailsAndNamesEmptySettings(string $secret, string $contact, string $expected): void
    {
        $tester = new CommandTester(new CheckDeployConfigCommand('prod', $secret, $contact, self::validThresholds()));

        self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Unset or invalid settings (empty, placeholder or out of range): '.$expected, $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
    }

    public function testDoesNotPrintValues(): void
    {
        $tester = new CommandTester(new CheckDeployConfigCommand('prod', 'do-not-print-this', '', self::validThresholds()));
        $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertStringNotContainsString('do-not-print-this', $tester->getDisplay().$tester->getErrorOutput());
    }

    public function testFailsAndNamesInvalidTimetableThresholds(): void
    {
        $tester = new CommandTester(new CheckDeployConfigCommand('prod', 'test-secret', 'test@example.invalid', new TimetableFreshnessPolicy('604800', '259200')));

        self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('TIMETABLE_WARN_AFTER_SECONDS / TIMETABLE_SUSPEND_AFTER_SECONDS', $tester->getErrorOutput());
    }

    private static function validThresholds(): TimetableFreshnessPolicy
    {
        return new TimetableFreshnessPolicy('259200', '604800');
    }
}
