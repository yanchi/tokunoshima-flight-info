<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Provider\Odpt;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guards CLAUDE.md §9: the test container never hands the ODPT client a
 * key, so it refuses before sending a request.
 */
final class OdptTestKeyTest extends KernelTestCase
{
    public function testTestContainerGivesTheClientNoKey(): void
    {
        $client = self::getContainer()->get('test.odpt_timetable_client');

        self::assertSame('', new \ReflectionProperty($client, 'consumerKey')->getValue($client));
    }
}
