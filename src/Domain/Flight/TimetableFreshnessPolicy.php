<?php

declare(strict_types=1);

namespace App\Domain\Flight;

/**
 * The two timetable freshness thresholds (docs/data-source-verification.md
 * §7 step 7 「時刻表の鮮度の閾値」). Unlike FreshnessPolicy, invalid values never
 * throw: a throwing constructor would break every page, while only the
 * timetable must stop being shown.
 *
 * The values arrive as raw environment strings, so an unset or malformed
 * variable reaches this class instead of failing in the container.
 */
final readonly class TimetableFreshnessPolicy
{
    /** Upper limit of the stop-publishing threshold; not configurable (§7 step 7). */
    public const int MAX_SUSPEND_AFTER_SECONDS = 604800;

    private ?int $warnAfterSeconds;
    private ?int $suspendAfterSeconds;

    public function __construct(string $warnAfterSeconds, string $suspendAfterSeconds)
    {
        $warn = self::parse($warnAfterSeconds);
        $suspend = self::parse($suspendAfterSeconds);

        if (null === $warn || null === $suspend || $warn >= $suspend || $suspend > self::MAX_SUSPEND_AFTER_SECONDS) {
            $this->warnAfterSeconds = null;
            $this->suspendAfterSeconds = null;

            return;
        }

        $this->warnAfterSeconds = $warn;
        $this->suspendAfterSeconds = $suspend;
    }

    public function isValid(): bool
    {
        return null !== $this->warnAfterSeconds;
    }

    /**
     * Elapsed time is compared, not JST calendar days, including fractions
     * of a second. An age equal to a threshold is still within it.
     */
    public function judge(\DateTimeInterface $fetchedAt, \DateTimeInterface $now): TimetableFreshness
    {
        if (null === $this->warnAfterSeconds || null === $this->suspendAfterSeconds) {
            return TimetableFreshness::InvalidThresholds;
        }

        // Sub-second parts are compared too, so a fetch a fraction of a
        // second ahead of now still counts as future.
        if ($fetchedAt > $now) {
            return TimetableFreshness::FutureDated;
        }

        // Sub-seconds count: rounding now down would make the age look up
        // to a second younger and delay the warning and the stop (§7 step 7).
        $fetched = \DateTimeImmutable::createFromInterface($fetchedAt);

        return match (true) {
            $now > $fetched->modify(\sprintf('+%d seconds', $this->suspendAfterSeconds)) => TimetableFreshness::Suspended,
            $now > $fetched->modify(\sprintf('+%d seconds', $this->warnAfterSeconds)) => TimetableFreshness::Warning,
            default => TimetableFreshness::Fresh,
        };
    }

    /**
     * Only plain positive decimal integers are accepted. \A and \z are used
     * because $ also matches before a trailing newline.
     */
    private static function parse(string $value): ?int
    {
        if (1 !== preg_match('/\A[1-9][0-9]*\z/', $value)) {
            return null;
        }

        // Longer strings could overflow; anything that long is far above the
        // seven-day limit anyway.
        if (\strlen($value) > 9) {
            return null;
        }

        return (int) $value;
    }
}
