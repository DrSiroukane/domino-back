<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\TurnTimeoutJob;
use App\Services\Game\Data\MatchState;
use Illuminate\Support\Str;

/**
 * Starts (or clears) the authoritative turn timer for whoever is to play.
 *
 * Must be called after every state change that can hand the turn to a human:
 * moves, timeouts, bot substitution and seat reclaim. The caller saves the
 * returned state; the job is dispatched after the surrounding transaction
 * commits so it never sees a state that was rolled back.
 */
final class TurnTimer
{
    public function arm(MatchState $match): MatchState
    {
        $seconds = $match->config->turnTimer;
        $enabled = $seconds > 0
            && ! $match->matchOver
            && ! $match->round->roundOver
            && ! in_array($match->round->currentPlayer, $match->botSeats, true);

        $match->turnToken = $enabled ? Str::uuid()->toString() : null;
        $match->turnExpiresAt = $enabled ? now()->timestamp + $seconds : null;

        return $match;
    }

    /** Dispatches the timeout job for the timer `arm()` set, if any. */
    public function dispatch(int $roomId, MatchState $match): void
    {
        if ($match->turnToken === null) {
            return;
        }

        TurnTimeoutJob::dispatch($roomId, $match->round->currentPlayer, $match->turnToken)
            ->delay($match->config->turnTimer)
            ->afterCommit();
    }
}
