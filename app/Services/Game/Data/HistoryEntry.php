<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

readonly class HistoryEntry
{
    public function __construct(
        public int $player,
        public string $action,   // 'play' | 'pass' | 'draw'
        public ?Tile $tile = null,
        public ?string $side = null,  // 'left' | 'right' | 'start'
        public ?string $call = null,  // HintCall value, when the player made a hint call with this play
        public ?int $callNumber = null, // the open-end number the call refers to (null for close_game)
    ) {}

    public function toArray(): array
    {
        $data = ['player' => $this->player, 'action' => $this->action];
        if ($this->tile !== null) {
            $data['tile'] = $this->tile->toArray();
        }
        if ($this->side !== null) {
            $data['side'] = $this->side;
        }
        if ($this->call !== null) {
            $data['call'] = $this->call;
            $data['callNumber'] = $this->callNumber;
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['player'],
            $data['action'],
            isset($data['tile']) ? Tile::fromArray($data['tile']) : null,
            $data['side'] ?? null,
            $data['call'] ?? null,
            isset($data['callNumber']) ? (int) $data['callNumber'] : null,
        );
    }
}
