<?php

declare(strict_types=1);

namespace App\Enum;

enum ZoneName: string
{
    case FRONT_STAGE = 'FRONT_STAGE';
    case ORCHESTRA = 'ORCHESTRA';
    case LEFT_BOX = 'LEFT_BOX';
    case RIGHT_BOX = 'RIGHT_BOX';
    case BALCONY = 'BALCONY';
}
