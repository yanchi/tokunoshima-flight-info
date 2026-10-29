<?php

declare(strict_types=1);

namespace App\Application\DTO;

enum DataAvailability: string
{
    /** No data has been fetched, so nothing can be shown. */
    case Unavailable = 'unavailable';

    /** Data exists but is older than the freshness threshold. */
    case Stale = 'stale';

    case Fresh = 'fresh';
}
