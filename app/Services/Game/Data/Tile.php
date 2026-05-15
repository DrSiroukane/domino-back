<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

readonly class Tile
{
    public function __construct(
        public int $a,
        public int $b,
        public int $id,
    ) {}

    public function toArray(): array
    {
        return ['a' => $this->a, 'b' => $this->b, 'id' => $this->id];
    }

    public static function fromArray(array $data): self
    {
        return new self((int) $data['a'], (int) $data['b'], (int) $data['id']);
    }
}
