<?php

declare(strict_types=1);

namespace App\Services\Game\Enums;

enum BlockedTiebreak: string
{
    case Sum = 'sum';
    case LowPlayer = 'low-player';
}
