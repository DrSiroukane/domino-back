<?php

declare(strict_types=1);

namespace App\Services\Game\Enums;

enum Difficulty: string
{
    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';
    case Relaxed = 'relaxed';
    case Tactician = 'tactician';
    case Strategist = 'strategist';
}
