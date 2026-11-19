<?php

declare(strict_types=1);

namespace App\Infrastructure\Contact;

use Symfony\Component\Mailer\Transport\Dsn;

/**
 * Judges a MAILER_DSN without printing it. Shared by the deploy check and the
 * contact form, so both agree on what counts as "mail will be delivered".
 */
final class MailerDsnCheck
{
    /** Schemes that deliver nothing: a form submitted through them is lost. */
    private const NON_DELIVERING_SCHEMES = ['null', 'native'];

    public static function delivers(string $dsn): bool
    {
        $parsed = self::parse($dsn);

        return null !== $parsed && !\in_array(strtolower($parsed->getScheme()), self::NON_DELIVERING_SCHEMES, true);
    }

    /**
     * True when the DSN parses to a scheme and a host. A raw "#" or "@" in the
     * password usually breaks that parsing and is caught, but not always (a
     * password that starts with digits can parse as a port). Only the single
     * DSN form "scheme://user:password@host:port" is supported: failover(...)
     * and roundrobin(...) are not, and are reported as malformed.
     */
    public static function isWellFormed(string $dsn): bool
    {
        return null !== self::parse($dsn);
    }

    private static function parse(string $dsn): ?Dsn
    {
        try {
            $parsed = Dsn::fromString(trim($dsn));
        } catch (\InvalidArgumentException) {
            return null;
        }

        return '' === $parsed->getHost() ? null : $parsed;
    }
}
