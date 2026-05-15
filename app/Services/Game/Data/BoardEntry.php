<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

interface BoardEntry
{
    public function toArray(): array;
}
