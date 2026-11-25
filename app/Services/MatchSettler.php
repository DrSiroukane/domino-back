<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Room;
use App\Services\Game\Data\MatchState;

/**
 * Finishes every state change (a move, a timeout, a forfeit, a bot substitution),
 * inside the room lock: bots play their turns, then the match is finalized, or
 * the next turn timer is armed and the state saved.
 */
final class MatchSettler
{
    public function __construct(
        private readonly MatchFinalizer $finalizer,
        private readonly TurnTimer $timer,
    ) {}

    /**
     * @return array{0: MatchState, 1: array<int, array{delta: int, newElo: int}>} the settled match, and seat → ELO result if it ended
     */
    public function settle(Room $room, MatchState $match): array
    {
        $match = BotPlayer::autoPlayBots($match);

        if ($match->matchOver) {
            $match->turnToken = null;
            $match->turnExpiresAt = null;

            return [$match, $this->finalizer->finalize($room, $match)];
        }

        $match = $this->timer->arm($match);
        $room->update(['match_state' => $match->toArray()]);
        $this->timer->dispatch($room->id, $match);

        return [$match, []];
    }
}
