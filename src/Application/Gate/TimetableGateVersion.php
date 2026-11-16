<?php

declare(strict_types=1);

namespace App\Application\Gate;

/**
 * A stored gate settings version. Only the latest version is in force; a
 * new one is made by the gate command alone, never by the sync.
 */
final readonly class TimetableGateVersion
{
    /**
     * @param \DateTimeImmutable $setAt when the command stored it, in UTC
     */
    public function __construct(
        public int $id,
        public TimetableGateSettings $settings,
        public \DateTimeImmutable $setAt,
    ) {
    }
}
