<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Exceptions\InvalidMoveException;
use App\Models\Room;
use App\Services\Game\Data\MatchState;
use App\Services\Game\GameEngine;

/**
 * Validates and applies a draw-from-boneyard move.
 *
 * Validations:
 *  - Match and round must be in progress.
 *  - It must be the player's turn.
 *  - The player must not be able to play any tile (forced draw).
 *  - The boneyard must not be empty.
 */
final class DrawTileAction
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
            throw new InvalidMoveException('You must play a tile before drawing.', 'can_play');
        }

        if (count($round->boneyard) === 0) {
            throw new InvalidMoveException('The boneyard is empty; pass instead.', 'boneyard_empty');
        }

        $newRound = GameEngine::drawTile($round, $playerIndex);
        $match->round = $newRound;

        $room->update(['match_state' => $match->toArray()]);

        return $match;
    }
}
