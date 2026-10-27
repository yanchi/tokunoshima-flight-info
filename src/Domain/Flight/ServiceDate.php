<?php

declare(strict_types=1);

namespace App\Domain\Flight;

/**
 * Calendar date of operation, always in Japan Standard Time.
 */
final readonly class ServiceDate
{
    public const TIMEZONE = 'Asia/Tokyo';

    private function __construct(private \DateTimeImmutable $date)
    {
    }

    /**
     * @param string $value date in Y-m-d format
     */
    public static function fromString(string $value): self
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(self::TIMEZONE));

        // Round-trip check rejects overflowing dates such as 2026-02-30.
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(\sprintf('Invalid service date: "%s".', $value));
        }

        return new self($date);
    }

    /**
     * Service date of the given instant as seen in JST, regardless of the
     * instant's own time zone.
     */
    public static function fromInstant(\DateTimeInterface $instant): self
    {
        $jst = \DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new \DateTimeZone(self::TIMEZONE));

        return new self($jst->setTime(0, 0));
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    public function toString(): string
    {
        return $this->date->format('Y-m-d');
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
