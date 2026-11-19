<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Infrastructure\Contact\MailerDsnCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test-only DSNs (mail.invalid is a reserved domain).
 */
final class MailerDsnCheckTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function dsns(): iterable
    {
        // dsn, well formed, delivers
        yield 'smtp with port' => ['smtp://u:p@mail.invalid:587', true, true];
        yield 'smtp with encoded at sign' => ['smtp://user%40mail.invalid:p@mail.invalid:587', true, true];
        yield 'smtp upper case scheme' => ['SMTP://u:p@mail.invalid:587', true, true];
        yield 'null transport' => ['null://null', true, false];
        yield 'native transport' => ['native://default', true, false];
        yield 'empty' => ['', false, false];
        yield 'no host' => ['smtp://', false, false];
        yield 'raw hash in the password' => ['smtp://u:pa#ss@mail.invalid:587', false, false];
        yield 'failover is not supported' => ['failover(smtp://a.invalid smtp://b.invalid)', false, false];
    }

    #[DataProvider('dsns')]
    public function testJudgesTheDsn(string $dsn, bool $wellFormed, bool $delivers): void
    {
        self::assertSame($wellFormed, MailerDsnCheck::isWellFormed($dsn));
        self::assertSame($delivers, MailerDsnCheck::delivers($dsn));
    }

    public function testAKnownGapIsDocumented(): void
    {
        // A password that starts with digits and holds a raw "#" parses as host "u" and
        // port 12, so the check cannot catch it (see the note on isWellFormed()).
        self::assertTrue(MailerDsnCheck::isWellFormed('smtp://u:12#ss@mail.invalid:587'));
    }
}
