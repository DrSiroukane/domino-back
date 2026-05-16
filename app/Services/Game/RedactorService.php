<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Services\Game\Data\ClientView;
use App\Services\Game\Data\MatchState;
use App\Services\Game\Data\Tile;

/**
 * Strips sensitive data from the canonical MatchState before sending to a client.
 *
 * Rules:
 *  - Opponents' tile pips are hidden; only hand SIZE is revealed.
 *  - Boneyard tiles are hidden; only the COUNT is revealed.
 *  - The board, history, scores, and config are fully public.
 */
final class RedactorService
{
    /**
     * @param  array<int, array{delta: int, newElo: int}>  $eloDeltas  Seat → ELO result; populated only when matchOver
     * @param  string[]  $seatNames  Display names for each seat in seat order
     */
    public function redact(MatchState $match, int $playerIndex, array $eloDeltas = [], array $seatNames = []): ClientView
    {
        $round = $match->round;

        $handCounts = array_map(fn (array $hand) => count($hand), $round->hands);

        $myHand = array_values(
            array_map(fn (Tile $t) => $t->toArray(), $round->hands[$playerIndex])
        );

        $board = array_values(
            array_map(fn ($entry) => $entry->toArray(), $round->board)
        );

        $history = array_values(
            array_map(fn ($entry) => $entry->toArray(), $round->history)
        );

        $eloChange = isset($eloDeltas[$playerIndex]) ? $eloDeltas[$playerIndex]['delta'] : null;
        $newElo = isset($eloDeltas[$playerIndex]) ? $eloDeltas[$playerIndex]['newElo'] : null;

        return new ClientView(
            playerIndex: $playerIndex,
            config: $match->config->toArray(),
            myHand: $myHand,
            handCounts: $handCounts,
            board: $board,
            leftEnd: $round->leftEnd,
            rightEnd: $round->rightEnd,
            boneyardCount: count($round->boneyard),
            currentPlayer: $round->currentPlayer,
            firstMover: $round->firstMover,
            mandatoryFirstTile: $round->mandatoryFirstTile,
            passes: $round->passes,
            roundOver: $round->roundOver,
            history: $history,
            roundResult: $round->roundResult?->toArray(),
            scores: $match->scores,
            matchOver: $match->matchOver,
            matchWinner: $match->matchWinner,
            lastWinner: $match->lastWinner,
            roundsPlayed: $match->roundsPlayed,
            turnExpiresAt: $match->turnExpiresAt,
            eloChange: $eloChange,
            newElo: $newElo,
            botSeats: $match->botSeats,
            lastRoundResult: $match->lastRoundResult,
            seatNames: $seatNames,
        );
    }
}
