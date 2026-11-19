<?php

declare(strict_types=1);

namespace App\Application\Contact;

use Symfony\Component\Mime\Address;

final class MailAddress
{
    /**
     * True when Symfony Mime can build an address from the value, i.e. it is
     * what the mailer will accept (a looser check would pass `a..b@example.test`
     * and then fail while sending).
     */
    public static function isValid(string $value): bool
    {
        try {
            new Address(trim($value));
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
