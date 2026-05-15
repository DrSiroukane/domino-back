<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

interface RoundResult
{
    public function getKind(): string;

    public function getWinner(): ?int;

    public function getWinningTeam(): ?int;

    public function toArray(): array;
}
