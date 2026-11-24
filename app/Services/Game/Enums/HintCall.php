<?php

declare(strict_types=1);

namespace App\Services\Game\Enums;

/**
 * Spoken hint calls a player may make to their partner when placing a tile
 * (house rule, 4-player partnership games only).
 *
 * A call always refers to the open (free) end of the tile just placed,
 * except CloseGame, which refers to the whole hand. Calls are public:
 * every seat sees them. The server validates WHEN a call is allowed,
 * never whether it is true — bluffing is part of the game.
 */
enum HintCall: string
{
    /** Opening only, after placing a double: "play the other side from the next player". */
    case HugIt = 'hug_it';

    /** Exactly one tile left with this number. */
    case One = 'one';

    /** At least two tiles left with this number. */
    case Good = 'good';

    /** No tile left with this number. */
    case Dead = 'dead';

    /** Holding the double of this number. */
    case MyHead = 'my_head';

    /** "My hand is low — we win if the game gets blocked." */
    case CloseGame = 'close_game';

    /** Whether the call is about the number on the open end (vs. the whole hand). */
    public function refersToNumber(): bool
    {
        return $this !== self::CloseGame;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
