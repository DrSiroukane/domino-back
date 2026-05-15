<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

readonly class RoundResultBlocked implements RoundResult
{
    public function __construct(
        public int $points,
        public array $handTotals,
        public ?int $winner = null,
        public ?int $winningTeam = null,
        public ?int $team0 = null,
        public ?int $team1 = null,
        public ?int $team0Min = null,
        public ?int $team1Min = null,
        public ?int $lowPlayer = null,
        public ?string $tiebreak = null,
        public bool $tied = false,
    ) {}

    public function getKind(): string
    {
        return 'blocked';
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
            'kind' => 'blocked',
            'points' => $this->points,
            'handTotals' => $this->handTotals,
        ];
        if ($this->winner !== null) {
            $data['winner'] = $this->winner;
        }
        if ($this->winningTeam !== null) {
            $data['winningTeam'] = $this->winningTeam;
        }
        if ($this->team0 !== null) {
            $data['team0'] = $this->team0;
        }
        if ($this->team1 !== null) {
            $data['team1'] = $this->team1;
        }
        if ($this->team0Min !== null) {
            $data['team0Min'] = $this->team0Min;
        }
        if ($this->team1Min !== null) {
            $data['team1Min'] = $this->team1Min;
        }
        if ($this->lowPlayer !== null) {
            $data['lowPlayer'] = $this->lowPlayer;
        }
        if ($this->tiebreak !== null) {
            $data['tiebreak'] = $this->tiebreak;
        }
        if ($this->tied) {
            $data['tied'] = true;
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['points'],
            array_map('intval', $data['handTotals']),
            isset($data['winner']) ? (int) $data['winner'] : null,
            isset($data['winningTeam']) ? (int) $data['winningTeam'] : null,
            isset($data['team0']) ? (int) $data['team0'] : null,
            isset($data['team1']) ? (int) $data['team1'] : null,
            isset($data['team0Min']) ? (int) $data['team0Min'] : null,
            isset($data['team1Min']) ? (int) $data['team1Min'] : null,
            isset($data['lowPlayer']) ? (int) $data['lowPlayer'] : null,
            $data['tiebreak'] ?? null,
            (bool) ($data['tied'] ?? false),
        );
    }
}
