<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CheckDeployConfigCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckDeployConfigCommandTest extends TestCase
{
    public function testPassesWhenAllSettingsAreSet(): void
    {
        $tester = new CommandTester(new CheckDeployConfigCommand('prod', 'test-secret', 'test@example.invalid'));

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
        $tester = new CommandTester(new CheckDeployConfigCommand($environment, 'test-secret', 'test@example.invalid'));

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
        yield 'both empty' => ['', '', 'APP_SECRET, CONTACT_ADDRESS'];
    }

    #[DataProvider('emptySettings')]
    public function testFailsAndNamesEmptySettings(string $secret, string $contact, string $expected): void
    {
        $tester = new CommandTester(new CheckDeployConfigCommand('prod', $secret, $contact));

        self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Empty settings: '.$expected, $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
    }

    public function testDoesNotPrintValues(): void
    {
        $tester = new CommandTester(new CheckDeployConfigCommand('prod', 'do-not-print-this', ''));
        $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertStringNotContainsString('do-not-print-this', $tester->getDisplay().$tester->getErrorOutput());
    }
}
