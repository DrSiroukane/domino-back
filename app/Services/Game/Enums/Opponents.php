<?php

declare(strict_types=1);

namespace App\Services\Game\Enums;

enum Opponents: string
{
    case Ai = 'ai';
    case Hotseat = 'hotseat';
}
