<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

readonly class BoardEntryStart implements BoardEntry
{
    public function __construct(
        public Tile $tile,
        public int $endA,
        public int $endB,
    ) {}

    public function toArray(): array
    {
        return [
            'tile' => $this->tile->toArray(),
            'side' => 'start',
            'endA' => $this->endA,
            'endB' => $this->endB,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            Tile::fromArray($data['tile']),
            (int) $data['endA'],
            (int) $data['endB'],
        );
    }
}
