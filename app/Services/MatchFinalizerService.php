<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Room;
use App\Models\User;
use App\Services\Game\Data\MatchState;
use Illuminate\Support\Facades\DB;

/**
 * Finalizes a completed match by updating ELO ratings, win/loss records,
 * and marking the room as finished — all inside a single DB transaction.
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

        $players = $room->players()->orderByPivot('created_at')->get();
        $numPlayers = count($players);

        if ($numPlayers < 2) {
            $room->update(['status' => 'finished']);

            return [];
        }

        $eloDeltas = [];

        DB::transaction(function () use ($match, $players, $numPlayers, &$eloDeltas, $room) {
            /** @var int[] $currentElos */
            $currentElos = $players->map(fn (User $u) => (int) $u->elo)->toArray();

            foreach ($players as $seat => $user) {
                // Bot seats don't earn stats
                if (in_array($seat, $match->botSeats, true)) {
                    $eloDeltas[$seat] = ['delta' => 0, 'newElo' => $currentElos[$seat]];

                    continue;
                }

                $isWinner = $this->isWinner($match, $seat);
                $avgOpponentElo = $this->avgOpponentElo($currentElos, $seat, $numPlayers);

                $expected = 1.0 / (1.0 + 10 ** (($avgOpponentElo - $currentElos[$seat]) / 400.0));
                $actual = $isWinner ? 1.0 : 0.0;
                $delta = (int) round(self::K_FACTOR * ($actual - $expected));
                $newElo = max(100, $currentElos[$seat] + $delta);

                $eloDeltas[$seat] = ['delta' => $delta, 'newElo' => $newElo];

                $user->elo = $newElo;
                $user->wins += $isWinner ? 1 : 0;
                $user->losses += $isWinner ? 0 : 1;
                $user->save();
            }

            $room->update(['status' => 'finished']);
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

    private function avgOpponentElo(array $elos, int $seat, int $numPlayers): float
    {
        $sum = 0;
        $count = 0;
        for ($i = 0; $i < $numPlayers; $i++) {
            if ($i !== $seat) {
                $sum += $elos[$i];
                $count++;
            }
        }

        return $count > 0 ? (float) ($sum / $count) : 1000.0;
    }
}
