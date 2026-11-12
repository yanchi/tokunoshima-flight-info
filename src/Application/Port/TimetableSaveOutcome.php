<?php

declare(strict_types=1);

namespace App\Application\Port;

enum TimetableSaveOutcome
{
    case Saved;
    /** The stored fetch started at the same second or later; nothing was replaced. */
    case NotNewerThanStored;
}
