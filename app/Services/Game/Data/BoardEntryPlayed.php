<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

readonly class BoardEntryPlayed implements BoardEntry
{
    public function __construct(
        public Tile $tile,
        public string $side,       // 'left' | 'right'
        public int $matchEnd,
        public int $newExposed,
    ) {}

    public function toArray(): array
    {
        return [
            'tile' => $this->tile->toArray(),
            'side' => $this->side,
            'matchEnd' => $this->matchEnd,
            'newExposed' => $this->newExposed,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            Tile::fromArray($data['tile']),
            $data['side'],
            (int) $data['matchEnd'],
            (int) $data['newExposed'],
        );
    }
}
