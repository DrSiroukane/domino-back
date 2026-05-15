<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Exceptions\InvalidMoveException;
use App\Models\Room;
use App\Services\Game\Data\MatchState;
use App\Services\Game\GameEngine;

/**
 * Validates and applies a play-tile move for a seated player.
 *
 * Validations (server-side, never trust the client):
 *  - Match and round must be in progress.
 *  - It must be the player's turn.
 *  - The tile must exist in the player's hand.
 *  - If a mandatory first tile is set, it must be played.
 *  - The chosen side must be legally playable.
 */
final class PlayTileAction
{
    public function execute(Room $room, int $playerIndex, int $tileId, string $side): MatchState
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

        // Resolve the tile from the player's hand
        $tile = null;
        foreach ($round->hands[$playerIndex] as $t) {
            if ($t->id === $tileId) {
                $tile = $t;
                break;
            }
        }

        if ($tile === null) {
            throw new InvalidMoveException('Tile not found in your hand.', 'tile_not_in_hand');
        }

        // Enforce mandatory first tile (round opener constraint)
        if ($round->mandatoryFirstTile !== null) {
            $m = $round->mandatoryFirstTile;
            if ($tile->a !== $m['a'] || $tile->b !== $m['b']) {
                throw new InvalidMoveException(
                    "You must play the mandatory first tile ({$m['a']}-{$m['b']}).",
                    'mandatory_first_tile',
                );
            }
        }

        $legalSides = GameEngine::legalSidesForTile($round, $tile);
        if (! in_array($side, $legalSides, true)) {
            throw new InvalidMoveException('That side is not a legal play.', 'illegal_side');
        }

        $newRound = GameEngine::playTile($round, $playerIndex, $tileId, $side);
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
