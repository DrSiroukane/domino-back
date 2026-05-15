<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

readonly class RoundResultDomino implements RoundResult
{
    public function __construct(
        public int $winner,
        public int $points,
        public array $handTotals,
        public bool $capicua = false,
        public int $capicuaBonus = 0,
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
            'capicua' => $this->capicua,
            'capicuaBonus' => $this->capicuaBonus,
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
            (bool) ($data['capicua'] ?? false),
            (int) ($data['capicuaBonus'] ?? 0),
            isset($data['winningTeam']) ? (int) $data['winningTeam'] : null,
        );
    }
}
