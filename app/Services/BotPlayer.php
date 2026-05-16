<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Game\Data\MatchState;
use App\Services\Game\Data\RoundState;
use App\Services\Game\GameEngine;

/**
 * Shared auto-play logic for bot-occupied seats.
 * Used by both GameController (immediate bot turns) and TurnTimeoutJob (timer-based).
 */
final class BotPlayer
{
    /**
     * Plays one full turn for a bot seat: picks a legal move, draws, or passes.
     * Handles round-end and match-end transitions internally.
     */
    public static function autoPlay(MatchState $match, int $playerIndex): MatchState
    {
        $round = $match->round;
        $hand = $round->hands[$playerIndex];
        $blockedTiebreak = $match->config->blockedTiebreak->value;

        // Mandatory opening tile (first move of the round)
        if ($round->mandatoryFirstTile !== null) {
            $m = $round->mandatoryFirstTile;
            foreach ($hand as $tile) {
                if ($tile->a === $m['a'] && $tile->b === $m['b']) {
                    return self::commitPlay(
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
                    return self::commitPlay(
                        $match,
                        GameEngine::playTile($round, $playerIndex, $tile->id, $sides[0]),
                    );
                }
            }
        }

        // Draw from boneyard if available
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

    /**
     * Keeps auto-playing while the current player is in botSeats.
     * Stops when a human player's turn arrives, or the match/round ends.
     * Guarded against infinite loops with a step limit.
     */
    public static function autoPlayBots(MatchState $match): MatchState
    {
        $limit = 200;
        while (! $match->matchOver && ! empty($match->botSeats)) {
            if (! in_array($match->round->currentPlayer, $match->botSeats, true)) {
                break;
            }
            $match = self::autoPlay($match, $match->round->currentPlayer);
            if (--$limit <= 0) {
                break;
            }
        }

        return $match;
    }

    private static function commitPlay(MatchState $match, RoundState $newRound): MatchState
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
