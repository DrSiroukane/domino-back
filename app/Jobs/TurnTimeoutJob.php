<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Services\BotPlayer;
use App\Services\Game\Data\MatchState;
use App\Services\GameBroadcaster;
use App\Services\MatchFinalizerService;
use App\Services\TurnTimerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fires when a player's turn timer expires.
 *
 * Under the room lock, verifies the stored turn token still matches (i.e. the
 * player hasn't moved), then auto-plays that turn and any following bot turns,
 * saves the state, finalizes the match if it ended, arms the next timer and
 * broadcasts after the commit.
 */
class TurnTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public readonly int $roomId,
        public readonly int $playerIndex,
        public readonly string $turnToken,
    ) {}

    public function handle(GameBroadcaster $broadcaster, MatchFinalizerService $finalizer, TurnTimerService $timer): void
    {
        try {
            $result = Room::withLock($this->roomId, function (Room $room) use ($finalizer, $timer) {
                if ($room->status !== RoomStatus::Playing) {
                    return null;
                }

                $match = MatchState::fromArray($room->match_state);

                // Bail if the player moved before the timeout fired
                if ($match->turnToken !== $this->turnToken
                    || $match->matchOver
                    || $match->round->roundOver
                    || $match->round->currentPlayer !== $this->playerIndex) {
                    return null;
                }

                $match = BotPlayer::autoPlay($match, $this->playerIndex);
                $match = BotPlayer::autoPlayBots($match);

                if ($match->matchOver) {
                    $match->turnToken = null;
                    $match->turnExpiresAt = null;

                    return [$room, $match, $finalizer->finalize($room, $match)];
                }

                $match = $timer->arm($match);
                $room->update(['match_state' => $match->toArray()]);
                $timer->dispatch($room->id, $match);

                return [$room, $match, []];
            });
        } catch (ModelNotFoundException) {
            return;
        }

        if ($result !== null) {
            [$room, $match, $eloDeltas] = $result;
            $broadcaster->broadcastAll($room, $match, $eloDeltas);
        }
    }
}
