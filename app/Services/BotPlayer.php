<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Game\Data\MatchState;
use App\Services\Game\Data\RoundState;
use App\Services\Game\GameEngine;
use App\Services\Game\HintCallAdvisor;

/**
 * Shared auto-play logic for bot-occupied seats.
 * Used by MatchSettler (bot turns after every change) and TurnTimeoutJob (an expired human turn).
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
                    $call = HintCallAdvisor::chooseCall($match->config, $round, $playerIndex, $tile, 'start');

                    return self::commitPlay(
                        $match,
                        GameEngine::playTile($round, $playerIndex, $tile->id, 'start', $call),
                    );
                }
            }
        }

        // With hint calls on: pick the move the table's calls favour, and make a call with it.
        if ($match->config->hintCallsActive() && GameEngine::canPlayAnyTile($round, $hand)) {
            $best = null;
            $bestScore = PHP_INT_MIN;
            foreach ($hand as $tile) {
                foreach (GameEngine::legalSidesForTile($round, $tile) as $side) {
                    $score = HintCallAdvisor::callScore($round, $playerIndex, $tile, $side);
                    if ($score > $bestScore) {
                        $best = [$tile, $side];
                        $bestScore = $score;
                    }
                }
            }
            [$tile, $side] = $best;
            $call = HintCallAdvisor::chooseCall($match->config, $round, $playerIndex, $tile, $side);

            return self::commitPlay(
                $match,
                GameEngine::playTile($round, $playerIndex, $tile->id, $side, $call),
            );
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

        // Pass (boneyard empty, no legal tile) — with "close the game" when the hand is low.
        $passCall = HintCallAdvisor::choosePassCall($match->config, $round, $playerIndex);
        $newRound = GameEngine::passTurn($round, $playerIndex, ['blockedTiebreak' => $blockedTiebreak], $passCall);
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
