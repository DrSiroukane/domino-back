<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

use App\Services\Game\Enums\BlockedTiebreak;
use App\Services\Game\Enums\Difficulty;
use App\Services\Game\Enums\Opponents;

readonly class MatchConfig
{
    public function __construct(
        public int $numPlayers,        // 2 or 4
        public bool $teams,
        public Opponents $opponents,
        public Difficulty $difficulty,
        public int $target,            // 100 | 150 | 200
        public int $turnTimer = 0,
        public BlockedTiebreak $blockedTiebreak = BlockedTiebreak::Sum,
        public bool $hintCalls = false, // house rule: spoken hint calls (4-player teams only)
    ) {}

    /** Hint calls are only meaningful in a 4-player partnership game. */
    public function hintCallsActive(): bool
    {
        return $this->hintCalls && $this->numPlayers === 4 && $this->teams;
    }

    public function toArray(): array
    {
        return [
            'numPlayers' => $this->numPlayers,
            'teams' => $this->teams,
            'opponents' => $this->opponents->value,
            'difficulty' => $this->difficulty->value,
            'target' => $this->target,
            'turnTimer' => $this->turnTimer,
            'blockedTiebreak' => $this->blockedTiebreak->value,
            'hintCalls' => $this->hintCalls,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['numPlayers'],
            (bool) $data['teams'],
            Opponents::from($data['opponents']),
            Difficulty::from($data['difficulty']),
            (int) $data['target'],
            (int) ($data['turnTimer'] ?? 0),
            BlockedTiebreak::from($data['blockedTiebreak'] ?? 'sum'),
            (bool) ($data['hintCalls'] ?? false),
        );
    }
}
