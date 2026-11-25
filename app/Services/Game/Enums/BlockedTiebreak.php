<?php

declare(strict_types=1);

namespace App\Services\Game\Enums;

/**
 * How a blocked round is decided: lowest pip total by side, or the lowest single player.
 */
enum BlockedTiebreak: string
{
    case Sum = 'sum';
    case LowPlayer = 'low-player';
}
