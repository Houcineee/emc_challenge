<?php

declare(strict_types=1);

namespace App\Enum;

enum TicketStatus: string
{
    case PAID = 'PAID';
    case REFUNDED = 'REFUNDED';
}
