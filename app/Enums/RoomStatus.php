<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a room is in its life: waiting for players, playing, or finished.
 */
enum RoomStatus: string
{
    case Waiting = 'waiting';
    case Playing = 'playing';
    case Finished = 'finished';
}
