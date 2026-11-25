<?php

declare(strict_types=1);

namespace App\Services\Game\Data;

/**
 * A tile on the board: the opening tile or one played on an end.
 */
interface BoardEntry
{
    public function toArray(): array;
}
