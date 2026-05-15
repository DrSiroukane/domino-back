<?php

declare(strict_types=1);

namespace App\Enums;

enum RoomStatus: string
{
    case Waiting = 'waiting';
    case Playing = 'playing';
    case Finished = 'finished';
}
