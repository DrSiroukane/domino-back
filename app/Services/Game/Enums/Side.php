<?php

declare(strict_types=1);

namespace App\Services\Game\Enums;

enum Side: string
{
    case Left = 'left';
    case Right = 'right';
    case Start = 'start';
}
