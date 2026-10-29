<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Checks the container wiring of the command; the rules are covered by the
 * unit test.
 */
final class CheckDeployConfigCommandTest extends KernelTestCase
{
    public function testIsRegisteredAndRefusesToRunOutsideProd(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('app:check-deploy-config'));

        self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('(current: test)', $tester->getErrorOutput());
    }
}
