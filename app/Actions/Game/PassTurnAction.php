<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Exceptions\InvalidMoveException;
use App\Models\Room;
use App\Services\Game\Data\MatchState;
use App\Services\Game\GameEngine;

/**
 * Validates and applies a pass move.
 *
 * Validations:
 *  - Match and round must be in progress.
 *  - It must be the player's turn.
 *  - The player must not be able to play any tile.
 *  - The boneyard must be empty (must draw before passing).
 */
final class PassTurnAction
{
    public function execute(Room $room, int $playerIndex): MatchState
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

        $blockedTiebreak = $match->config->blockedTiebreak->value;
        $newRound = GameEngine::passTurn($round, $playerIndex, ['blockedTiebreak' => $blockedTiebreak]);
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
