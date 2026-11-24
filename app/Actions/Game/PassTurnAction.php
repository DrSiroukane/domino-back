<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Exceptions\InvalidMoveException;
use App\Models\Room;
use App\Services\Game\Data\MatchState;
use App\Services\Game\Enums\HintCall;
use App\Services\Game\GameEngine;

/**
 * Validates and applies a pass move.
 *
 * Validations:
 *  - Match and round must be in progress.
 *  - It must be the player's turn.
 *  - The player must not be able to play any tile.
 *  - The boneyard must be empty (must draw before passing).
 *  - A hint call, if any, must be allowed with a pass (only "close the game").
 */
final class PassTurnAction
{
    public function execute(Room $room, int $playerIndex, ?string $call = null): MatchState
    {
        $match = MatchState::fromArray($room->match_state);

        if ($match->matchOver) {
            throw new InvalidMoveException('The match is already over.', 'match_over');
        }

        $round = $match->round;

        if ($round->roundOver) {
            throw new InvalidMoveException('The round is already over.', 'round_over');
        }

        if ($round->currentPlayer !== $playerIndex) {
            throw new InvalidMoveException('It is not your turn.', 'not_your_turn');
        }

        if (GameEngine::canPlayAnyTile($round, $round->hands[$playerIndex])) {
            throw new InvalidMoveException('You cannot pass while you have a playable tile.', 'can_play');
        }

        if (count($round->boneyard) > 0) {
            throw new InvalidMoveException('You must draw from the boneyard before passing.', 'must_draw');
        }

        $hintCall = null;
        if ($call !== null) {
            $hintCall = HintCall::tryFrom($call);
            if ($hintCall === null || ! in_array($hintCall, GameEngine::legalPassCalls($match->config, $round, $playerIndex), true)) {
                throw new InvalidMoveException('That hint call is not allowed right now.', 'illegal_call');
            }
        }

        $blockedTiebreak = $match->config->blockedTiebreak->value;
        $newRound = GameEngine::passTurn($round, $playerIndex, ['blockedTiebreak' => $blockedTiebreak], $hintCall);
        $match->round = $newRound;

        if ($newRound->roundOver) {
            $match = GameEngine::applyRoundResult($match);
            if (! $match->matchOver) {
                $match = GameEngine::startNextRound($match);
            }
        }

        $room->update(['match_state' => $match->toArray()]);

        return $match;
    }
}
