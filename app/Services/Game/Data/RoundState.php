<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

class RoundState
{
    /**
     * @param  Tile[][]  $hands
     * @param  BoardEntry[]  $board
     * @param  Tile[]  $boneyard
     * @param  HistoryEntry[]  $history
     * @param  array{a:int,b:int}|null  $mandatoryFirstTile
     */
    public function __construct(
        public int $numPlayers,
        public bool $teams,
        public array $hands,
        public array $board,
        public ?int $leftEnd,
        public ?int $rightEnd,
        public array $boneyard,
        public int $currentPlayer,
        public int $firstMover,
        public ?array $mandatoryFirstTile,
        public int $passes,
        public bool $roundOver,
        public array $history,
        public ?RoundResult $roundResult,
    ) {}

    public function toArray(): array
    {
        return [
            'numPlayers' => $this->numPlayers,
            'teams' => $this->teams,
            'hands' => array_map(
                fn (array $hand) => array_values(array_map(fn (Tile $t) => $t->toArray(), $hand)),
                $this->hands
            ),
            'board' => array_values(
                array_map(fn (BoardEntry $e) => $e->toArray(), $this->board)
            ),
            'leftEnd' => $this->leftEnd,
            'rightEnd' => $this->rightEnd,
            'boneyard' => array_values(
                array_map(fn (Tile $t) => $t->toArray(), $this->boneyard)
            ),
            'currentPlayer' => $this->currentPlayer,
            'firstMover' => $this->firstMover,
            'mandatoryFirstTile' => $this->mandatoryFirstTile,
            'passes' => $this->passes,
            'roundOver' => $this->roundOver,
            'history' => array_values(
                array_map(fn (HistoryEntry $e) => $e->toArray(), $this->history)
            ),
            'roundResult' => $this->roundResult?->toArray(),
        ];
    }

    public static function fromArray(array $data): self
    {
        $hands = array_map(
            fn (array $hand) => array_map(fn (array $t) => Tile::fromArray($t), $hand),
            $data['hands']
        );

        $board = array_map(
            fn (array $e) => $e['side'] === 'start'
                ? BoardEntryStart::fromArray($e)
                : BoardEntryPlayed::fromArray($e),
            $data['board']
        );

        $boneyard = array_map(fn (array $t) => Tile::fromArray($t), $data['boneyard']);
        $history = array_map(fn (array $e) => HistoryEntry::fromArray($e), $data['history']);

        $roundResult = null;
        if (! empty($data['roundResult'])) {
            $roundResult = $data['roundResult']['kind'] === 'domino'
                ? RoundResultDomino::fromArray($data['roundResult'])
                : RoundResultBlocked::fromArray($data['roundResult']);
        }

        return new self(
            numPlayers: (int) $data['numPlayers'],
            teams: (bool) $data['teams'],
            hands: $hands,
            board: $board,
            leftEnd: isset($data['leftEnd']) && $data['leftEnd'] !== null ? (int) $data['leftEnd'] : null,
            rightEnd: isset($data['rightEnd']) && $data['rightEnd'] !== null ? (int) $data['rightEnd'] : null,
            boneyard: $boneyard,
            currentPlayer: (int) $data['currentPlayer'],
            firstMover: (int) $data['firstMover'],
            mandatoryFirstTile: $data['mandatoryFirstTile'] ?? null,
            passes: (int) $data['passes'],
            roundOver: (bool) $data['roundOver'],
            history: $history,
            roundResult: $roundResult,
        );
    }
}
