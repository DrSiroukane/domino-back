<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\RoomStatus;
use App\Events\GameStateUpdated;
use App\Models\Room;
use App\Services\Game\Data\MatchState;
use App\Services\Game\Data\RoundState;
use App\Services\Game\GameEngine;
use App\Services\Game\RedactorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Fires when a player's turn timer expires.
 *
 * Checks that the stored turn token still matches (i.e. the player hasn't
 * already moved), then auto-plays: pick a legal tile, draw from the boneyard,
 * or pass — whichever is applicable — then broadcasts the new state and
 * schedules the next timeout.
 */
class TurnTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $roomId,
        public readonly int $playerIndex,
        public readonly string $turnToken,
    ) {}

    public function handle(RedactorService $redactor): void
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

        $match = $this->autoPlay($match, $this->playerIndex);

        // Assign a fresh token for the next player's turn if the timer is still on
        $timerSeconds = $match->config->turnTimer;
        $nextTimerEnabled = $timerSeconds > 0 && ! $match->matchOver && ! $match->round->roundOver;

        $match->turnToken = $nextTimerEnabled ? Str::uuid()->toString() : null;
        $match->turnExpiresAt = $nextTimerEnabled ? now()->timestamp + $timerSeconds : null;

        $room->update(['match_state' => $match->toArray()]);

        // Broadcast redacted state to every seat
        $numPlayers = count($match->round->hands);
        for ($i = 0; $i < $numPlayers; $i++) {
            event(new GameStateUpdated($room->id, $redactor->redact($match, $i)));
        }

        // Schedule the next player's timeout
        if ($nextTimerEnabled) {
            self::dispatch($room->id, $match->round->currentPlayer, $match->turnToken)
                ->delay($timerSeconds);
        }
    }

    private function autoPlay(MatchState $match, int $playerIndex): MatchState
    {
        $round = $match->round;
        $hand = $round->hands[$playerIndex];
        $blockedTiebreak = $match->config->blockedTiebreak->value;

        // Mandatory opening tile (first move of the round)
        if ($round->mandatoryFirstTile !== null) {
            $m = $round->mandatoryFirstTile;
            foreach ($hand as $tile) {
                if ($tile->a === $m['a'] && $tile->b === $m['b']) {
                    return $this->commitPlay(
                        $match,
                        GameEngine::playTile($round, $playerIndex, $tile->id, 'start'),
                    );
                }
            }
        }

        // Play the first legal tile
        if (GameEngine::canPlayAnyTile($round, $hand)) {
            foreach ($hand as $tile) {
                $sides = GameEngine::legalSidesForTile($round, $tile);
                if (! empty($sides)) {
                    return $this->commitPlay(
                        $match,
                        GameEngine::playTile($round, $playerIndex, $tile->id, $sides[0]),
                    );
                }
            }
        }

        // Draw from the boneyard if available
        if (! empty($round->boneyard)) {
            $match->round = GameEngine::drawTile($round, $playerIndex);

            return $match;
        }

        // Pass (boneyard empty, no legal tile)
        $newRound = GameEngine::passTurn($round, $playerIndex, ['blockedTiebreak' => $blockedTiebreak]);
        $match->round = $newRound;

        if ($newRound->roundOver) {
            $match = GameEngine::applyRoundResult($match);
            if (! $match->matchOver) {
                $match = GameEngine::startNextRound($match);
            }
        }

        return $match;
    }

    private function commitPlay(MatchState $match, RoundState $newRound): MatchState
    {
        $match->round = $newRound;

        if ($newRound->roundOver) {
            $match = GameEngine::applyRoundResult($match);
            if (! $match->matchOver) {
                $match = GameEngine::startNextRound($match);
            }
        }

        return $match;
    }
}
