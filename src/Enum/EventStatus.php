<?php

declare(strict_types=1);

namespace App\Enum;

enum EventStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case CANCELLED = 'CANCELLED';
}
