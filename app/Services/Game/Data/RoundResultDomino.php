<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

/**
 * A round won by playing the last tile, and the points awarded.
 */
final readonly class RoundResultDomino implements RoundResult
{
    public function __construct(
        public int $winner,
        public int $points,
        public array $handTotals,
        public ?int $winningTeam = null,
    ) {}

    public function getKind(): string
    {
        return 'domino';
    }

    public function getWinner(): ?int
    {
        return $this->winner;
    }

    public function getWinningTeam(): ?int
    {
        return $this->winningTeam;
    }

    public function toArray(): array
    {
        $data = [
            'kind' => 'domino',
            'winner' => $this->winner,
            'points' => $this->points,
            'handTotals' => $this->handTotals,
        ];
        if ($this->winningTeam !== null) {
            $data['winningTeam'] = $this->winningTeam;
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['winner'],
            (int) $data['points'],
            array_map('intval', $data['handTotals']),
            isset($data['winningTeam']) ? (int) $data['winningTeam'] : null,
        );
    }
}
