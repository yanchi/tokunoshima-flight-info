<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * The timetable for a direction could not be fetched or did not pass
 * validation. Messages must never contain credentials or request URLs.
 */
final class TimetableFetchFailed extends \RuntimeException
{
}
