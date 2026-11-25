<?php

declare(strict_types=1);

// Pest setup: feature tests run on the Laravel test case (HTTP, database, events),
// and helpers shared by several test files.
use App\Services\Game\Data\MatchState;
use App\Services\Game\Data\Tile;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/** The opening double of a match's first round, from the hand of the seat that opens. */
function openingDoubleTile(MatchState $match): Tile
{
    $round = $match->round;
    $double = $round->openingDouble;
    foreach ($round->hands[$round->currentPlayer] as $t) {
        if ($t->a === $double['a'] && $t->b === $double['b']) {
            return $t;
        }
    }
    throw new RuntimeException('The opening double is not in the opener\'s hand.');
}
