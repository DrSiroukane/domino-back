<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

/**
 * The redacted match state sent to a specific player.
 * Opponents' tile pips and boneyard contents are hidden.
 */
readonly class ClientView
{
    /**
     * @param  int[]  $handCounts  Tile count per seat (all players, including self)
     * @param  array[]  $myHand  Full tile objects for the receiving player only
     * @param  array[]  $board  Board entries (public)
     * @param  array[]  $history  History entries (public)
     * @param  int[]  $scores  Match scores per seat/team
     */
    public function __construct(
        public int $playerIndex,
        public array $config,
        public array $myHand,
        public array $handCounts,
        public array $board,
        public ?int $leftEnd,
        public ?int $rightEnd,
        public int $boneyardCount,
        public int $currentPlayer,
        public int $firstMover,
        public ?array $mandatoryFirstTile,
        public int $passes,
        public bool $roundOver,
        public array $history,
        public ?array $roundResult,
        public array $scores,
        public bool $matchOver,
        public ?int $matchWinner,
        public ?int $lastWinner,
        public int $roundsPlayed,
        public ?int $turnExpiresAt = null,
        /** ELO change for this player after the match ended (null while match is in progress). */
        public ?int $eloChange = null,
        /** New ELO rating for this player after the match ended. */
        public ?int $newElo = null,
        /** Seat indices currently occupied by AI bots. */
        public array $botSeats = [],
    ) {}

    public function toArray(): array
    {
        return [
            'playerIndex' => $this->playerIndex,
            'config' => $this->config,
            'myHand' => $this->myHand,
            'handCounts' => $this->handCounts,
            'board' => $this->board,
            'leftEnd' => $this->leftEnd,
            'rightEnd' => $this->rightEnd,
            'boneyardCount' => $this->boneyardCount,
            'currentPlayer' => $this->currentPlayer,
            'firstMover' => $this->firstMover,
            'mandatoryFirstTile' => $this->mandatoryFirstTile,
            'passes' => $this->passes,
            'roundOver' => $this->roundOver,
            'history' => $this->history,
            'roundResult' => $this->roundResult,
            'scores' => $this->scores,
            'matchOver' => $this->matchOver,
            'matchWinner' => $this->matchWinner,
            'lastWinner' => $this->lastWinner,
            'roundsPlayed' => $this->roundsPlayed,
            'turnExpiresAt' => $this->turnExpiresAt,
            'eloChange' => $this->eloChange,
            'newElo' => $this->newElo,
            'botSeats' => $this->botSeats,
        ];
    }
}
