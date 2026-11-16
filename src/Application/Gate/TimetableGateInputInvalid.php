<?php

declare(strict_types=1);

namespace App\Application\Gate;

/**
 * An operator input to the gate command is missing or malformed. Kept apart
 * from other InvalidArgumentExceptions, so an internal failure is never shown
 * as a mistake in the input.
 */
final class TimetableGateInputInvalid extends \InvalidArgumentException
{
}
