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
     * True when the DSN has a scheme and a host (so a raw "#" or "@" in the
     * password, which breaks the parsing, is caught).
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
