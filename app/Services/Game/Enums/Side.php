<?php

declare(strict_types=1);

namespace App\Services\Game\Enums;

/**
 * Where a tile goes: the opening position or one of the two ends.
 */
enum Side: string
{
    case Left = 'left';
    case Right = 'right';
    case Start = 'start';
}
