<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

class MatchState
{
    /**
     * @param  int[]  $scores
     */
    public function __construct(
        public MatchConfig $config,
        public RoundState $round,
        public array $scores,
        public bool $matchOver,
        public ?int $matchWinner,
        public ?int $lastWinner,
        public int $roundsPlayed,
    ) {}

    public function toArray(): array
    {
        return [
            'config' => $this->config->toArray(),
            'round' => $this->round->toArray(),
            'scores' => $this->scores,
            'matchOver' => $this->matchOver,
            'matchWinner' => $this->matchWinner,
            'lastWinner' => $this->lastWinner,
            'roundsPlayed' => $this->roundsPlayed,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            config: MatchConfig::fromArray($data['config']),
            round: RoundState::fromArray($data['round']),
            scores: array_map('intval', $data['scores']),
            matchOver: (bool) $data['matchOver'],
            matchWinner: isset($data['matchWinner']) && $data['matchWinner'] !== null ? (int) $data['matchWinner'] : null,
            lastWinner: isset($data['lastWinner']) && $data['lastWinner'] !== null ? (int) $data['lastWinner'] : null,
            roundsPlayed: (int) $data['roundsPlayed'],
        );
    }
}
