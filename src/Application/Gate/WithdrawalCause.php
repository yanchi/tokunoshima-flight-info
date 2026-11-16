<?php

declare(strict_types=1);

namespace App\Application\Gate;

/**
 * Why a direction was withdrawn from a gate settings version. Either way it
 * stays withdrawn until a new version is set (§7 step 7).
 */
enum WithdrawalCause: string
{
    /** Comparison (a): the compared items inside the compared period changed. */
    case ComparedItemsChanged = 'compared_items_changed';
    /** The operator withdrew it with the gate command. */
    case Manual = 'manual';
}
