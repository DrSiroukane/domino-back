<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\RoomStatus;
use App\Events\GameStateUpdated;
use App\Models\Room;
use App\Services\BotPlayer;
use App\Services\Game\Data\MatchState;
use App\Services\Game\RedactorService;
use App\Services\MatchFinalizerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Fires when a player's turn timer expires.
 *
 * Verifies the stored turn token still matches (i.e. the player hasn't already
 * moved), then auto-plays: pick a legal tile, draw from the boneyard, or pass.
 * Broadcasts the new state and schedules the next timeout if needed.
 */
class TurnTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $roomId,
        public readonly int $playerIndex,
        public readonly string $turnToken,
    ) {}

    public function handle(RedactorService $redactor, MatchFinalizerService $finalizer): void
    {
        $room = Room::find($this->roomId);
        if (! $room || $room->status !== RoomStatus::Playing) {
            return;
        }

        $match = MatchState::fromArray($room->match_state);

        // Bail if the player moved before the timeout fired
        if ($match->turnToken !== $this->turnToken) {
            return;
        }
        if ($match->matchOver || $match->round->roundOver) {
            return;
        }
        if ($match->round->currentPlayer !== $this->playerIndex) {
            return;
        }

        // Auto-play this turn, then any subsequent bot turns
        $match = BotPlayer::autoPlay($match, $this->playerIndex);
        $match = BotPlayer::autoPlayBots($match);

        // Finalize match if it just ended
        $eloDeltas = [];
        if ($match->matchOver) {
            $eloDeltas = $finalizer->finalize($room, $match);
        }

        // Assign a fresh token for the next human player's turn if the timer is still on
        $timerSeconds = $match->config->turnTimer;
        $nextTimerEnabled = $timerSeconds > 0
            && ! $match->matchOver
            && ! $match->round->roundOver
            && ! \in_array($match->round->currentPlayer, $match->botSeats, true);

        $match->turnToken = $nextTimerEnabled ? Str::uuid()->toString() : null;
        $match->turnExpiresAt = $nextTimerEnabled ? now()->timestamp + $timerSeconds : null;

        if (! $match->matchOver) {
            $room->update(['match_state' => $match->toArray()]);
        }

        // Broadcast redacted state to every seat
        $seatNames = $room->getSeatNames();
        $numPlayers = \count($match->round->hands);
        for ($i = 0; $i < $numPlayers; $i++) {
            event(new GameStateUpdated($room->id, $redactor->redact($match, $i, $eloDeltas, $seatNames)));
        }

        // Schedule the next human player's timeout
        if ($nextTimerEnabled) {
            self::dispatch($room->id, $match->round->currentPlayer, $match->turnToken)
                ->delay($timerSeconds);
        }
    }
}
