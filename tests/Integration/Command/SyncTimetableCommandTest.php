<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Checks the container wiring of the command. The test environment has no
 * consumer key, so the client fails before any request is made and the
 * real API is never called.
 */
final class SyncTimetableCommandTest extends KernelTestCase
{
    public function testIsRegisteredAndFailsWhenNothingCanBeFetched(): void
    {
        $kernel = self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $tester = new CommandTester(new Application($kernel)->find('app:timetable:sync'));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertEquals(0, $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM timetable_fetch_record'));
    }
}
