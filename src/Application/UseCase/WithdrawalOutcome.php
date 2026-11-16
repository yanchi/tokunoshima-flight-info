<?php

declare(strict_types=1);

namespace App\Application\UseCase;

enum WithdrawalOutcome
{
    case Withdrawn;
    /** Already withdrawn from the version in force; the first withdrawal is kept. */
    case AlreadyWithdrawn;
    /** No version has been set, so nothing is published and nothing changed. */
    case NoVersion;
}
