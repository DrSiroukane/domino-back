<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\User;
use App\Services\Game\Data\MatchState;
use Illuminate\Support\Facades\DB;

/**
 * Finalizes a completed match: saves the final state, updates ELO ratings and
 * win/loss records, and marks the room as finished — in one transaction.
 *
 * Call it while holding the room lock (Room::withLock) so two requests that
 * both end the match cannot finalize it twice.
 */
final class MatchFinalizerService
{
    private const K_FACTOR = 32;

    /**
     * @return array<int, array{delta: int, newElo: int}> seat → ELO result
     */
    public function finalize(Room $room, MatchState $match): array
    {
        if (! $match->matchOver) {
            return [];
        }

        // Guard against double-finalization — once a room is finished, stats are immutable.
        if ($room->status === RoomStatus::Finished) {
            return [];
        }

        /** @var array<int, User> $bySeat */
        $bySeat = [];
        foreach ($room->seatedPlayers() as $user) {
            $bySeat[(int) $user->pivot->seat] = $user;
        }

        $eloDeltas = [];

        DB::transaction(function () use ($match, $bySeat, &$eloDeltas, $room) {
            $room->update([
                'status' => RoomStatus::Finished,
                'match_state' => $match->toArray(),
            ]);

            if (count($bySeat) < 2) {
                return;
            }

            $currentElos = array_map(fn (User $u) => (int) $u->elo, $bySeat);

            foreach ($bySeat as $seat => $user) {
                $forfeited = in_array($seat, $match->forfeitedSeats, true);

                // A seat a bot took over after a disconnect earns nothing either way.
                // A seat whose player chose to leave counts as a loss, so quitting can't dodge one.
                if (in_array($seat, $match->botSeats, true) && ! $forfeited) {
                    $eloDeltas[$seat] = ['delta' => 0, 'newElo' => $currentElos[$seat]];

                    continue;
                }

                $isWinner = ! $forfeited && $this->isWinner($match, $seat);
                $opponentElo = $this->avgOpponentElo($match, $currentElos, $seat);

                $expected = 1.0 / (1.0 + 10 ** (($opponentElo - $currentElos[$seat]) / 400.0));
                $delta = (int) round(self::K_FACTOR * (($isWinner ? 1.0 : 0.0) - $expected));
                $newElo = max(100, $currentElos[$seat] + $delta);

                $eloDeltas[$seat] = ['delta' => $delta, 'newElo' => $newElo];

                $user->elo = $newElo;
                $user->wins += $isWinner ? 1 : 0;
                $user->losses += $isWinner ? 0 : 1;
                $user->save();
            }
        });

        return $eloDeltas;
    }

    private function isWinner(MatchState $match, int $seat): bool
    {
        if ($match->config->teams) {
            return $match->matchWinner !== null && ($seat % 2) === $match->matchWinner;
        }

        return $match->matchWinner === $seat;
    }

    /**
     * Average rating of the seat's opponents: the other team in a partnership
     * game (the partner is not an opponent), everyone else otherwise.
     *
     * @param  array<int, int>  $elos  seat → rating
     */
    private function avgOpponentElo(MatchState $match, array $elos, int $seat): float
    {
        $opponents = array_filter(
            $elos,
            fn (int $s) => $match->config->teams ? ($s % 2) !== ($seat % 2) : $s !== $seat,
            ARRAY_FILTER_USE_KEY,
        );

        return $opponents === [] ? 1000.0 : array_sum($opponents) / count($opponents);
    }
}
