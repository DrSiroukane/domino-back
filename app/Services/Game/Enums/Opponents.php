<?php

declare(strict_types=1);

namespace App\Services\Game\Enums;

/**
 * Who fills the other seats: bots or people at the same device.
 */
enum Opponents: string
{
    case Ai = 'ai';
    case Hotseat = 'hotseat';
}
