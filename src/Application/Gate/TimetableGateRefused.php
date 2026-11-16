<?php

declare(strict_types=1);

namespace App\Application\Gate;

/**
 * The gate command changed nothing. The message is a fixed sentence for
 * the operator, with directions and dates only.
 */
final class TimetableGateRefused extends \RuntimeException
{
}
