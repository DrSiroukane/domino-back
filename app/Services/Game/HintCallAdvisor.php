<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Services\Game\Data\MatchConfig;
use App\Services\Game\Data\RoundState;
use App\Services\Game\Data\Tile;
use App\Services\Game\Enums\Difficulty;
use App\Services\Game\Enums\HintCall;

/**
 * Bot-side use of hint calls: which call to make with a play, and how the
 * calls already made at the table make one move better than another.
 *
 * Bots tell the truth by default and bluff rarely (stronger bots a little
 * more often), never near the end of a round.
 */
final class HintCallAdvisor
{
    /** Chance that a bot bluffs "dead" while holding exactly one tile of the number. */
    public static function bluffRate(Difficulty $difficulty): float
    {
        return match ($difficulty) {
            Difficulty::Easy, Difficulty::Relaxed => 0.0,
            Difficulty::Medium => 0.05,
            Difficulty::Hard, Difficulty::Tactician, Difficulty::Strategist => 0.1,
        };
    }

    /**
     * Picks the call a bot makes when playing `$tile` on `$side` (state is before the play).
     *
     * @param  float|null  $roll  Random number in [0,1) for the bluff decision (injectable for tests).
     */
    public static function chooseCall(
        MatchConfig $config,
        RoundState $state,
        int $player,
        Tile $tile,
        string $side,
        ?float $roll = null,
    ): ?HintCall {
        $legal = GameEngine::legalCalls($config, $state, $player, $tile);
        if ($legal === []) {
            return null;
        }

        $remaining = array_values(array_filter($state->hands[$player], fn (Tile $t) => $t->id !== $tile->id));

        // Opening double: ask the partner to play the other side when we can't follow the number ourselves.
        if (in_array(HintCall::HugIt, $legal, true)) {
            return self::countWith($remaining, $tile->a) <= 1 ? HintCall::HugIt : null;
        }

        $n = GameEngine::exposedNumber($state, $tile, $side);
        if ($n === null) {
            return null;
        }

        $count = self::countWith($remaining, $n);
        $hasDouble = (bool) array_filter($remaining, fn (Tile $t) => $t->a === $n && $t->b === $n);

        // A very low hand: ask the partner to help block the game.
        if (count($remaining) >= 3 && GameEngine::handPips($remaining) <= 6) {
            return HintCall::CloseGame;
        }

        if ($hasDouble && ! self::hasCalled($state, $player, $n, HintCall::MyHead)) {
            return HintCall::MyHead;
        }

        if ($count === 1) {
            $roll ??= mt_rand() / (mt_getrandmax() + 1);
            // Bluff only with enough tiles left that the partner doesn't need exact information yet.
            if (count($remaining) >= 4 && $roll < self::bluffRate($config->difficulty)) {
                return HintCall::Dead;
            }

            return HintCall::One;
        }

        return $count === 0 ? HintCall::Dead : HintCall::Good;
    }

    /**
     * The call a bot makes while passing: "close the game" when its hand is low.
     */
    public static function choosePassCall(MatchConfig $config, RoundState $state, int $player): ?HintCall
    {
        if (GameEngine::legalPassCalls($config, $state, $player) === []) {
            return null;
        }
        $hand = $state->hands[$player];

        return count($hand) >= 3 && GameEngine::handPips($hand) <= 6 ? HintCall::CloseGame : null;
    }

    /**
     * Scores a legal move using the calls and passes so far this round. Higher is better; 0 = no information.
     */
    public static function scoreMove(RoundState $state, int $player, Tile $tile, string $side): int
    {
        $n = GameEngine::exposedNumber($state, $tile, $side);
        $partner = ($player + 2) % 4;
        $calls = self::latestCalls($state);
        $score = 0;

        if ($n !== null) {
            $partnerCall = $calls[$partner][$n] ?? null;
            $score += match ($partnerCall) {
                HintCall::Good, HintCall::MyHead => 3,
                HintCall::One => 1,
                HintCall::Dead => -2,
                default => 0,
            };

            foreach ([($player + 1) % 4, ($player + 3) % 4] as $opponent) {
                $score += match ($calls[$opponent][$n] ?? null) {
                    HintCall::Dead => 2,
                    HintCall::Good, HintCall::MyHead => -2,
                    default => 0,
                };
            }
        }

        // Passes: whoever passed holds none of the numbers that were open then.
        // Don't leave the partner stuck again; do leave the opponents stuck.
        if ($n !== null) {
            $other = $side === 'left' ? $state->rightEnd : ($side === 'right' ? $state->leftEnd : $n);
            $score += self::passScore(GameEngine::passedNumbers($state), $player, $n, $other);
        }

        // Answer the partner's "hug it": after the opponent plays one side of the opening double, play the other.
        $plays = array_values(array_filter($state->history, fn ($h) => $h->action === 'play'));
        if (count($plays) === 2
            && $plays[0]->player === $partner
            && $plays[0]->call === HintCall::HugIt->value
            && $plays[1]->player !== $player
            && $side !== $plays[1]->side) {
            $score += 4;
        }

        return $score;
    }

    /**
     * How the open ends left by a move (`$n` new, `$other` untouched) suit the
     * players who passed: -4 if the partner can follow neither end, -1 if not
     * the new one; +2 per opponent stuck on both ends, +1 if only the new one.
     *
     * @param  array<int, list<int>>  $passed  GameEngine::passedNumbers()
     */
    public static function passScore(array $passed, int $player, int $n, ?int $other): int
    {
        $lacks = fn (int $p, ?int $x): bool => $x !== null && in_array($x, $passed[$p] ?? [], true);
        $stuck = fn (int $p): bool => $lacks($p, $n) && ($other === null || $lacks($p, $other));

        $partner = ($player + 2) % 4;
        $score = $stuck($partner) ? -4 : ($lacks($partner, $n) ? -1 : 0);
        foreach ([($player + 1) % 4, ($player + 3) % 4] as $opponent) {
            $score += $stuck($opponent) ? 2 : ($lacks($opponent, $n) ? 1 : 0);
        }

        return $score;
    }

    /**
     * Latest number-call made by each player this round, keyed [player][number].
     *
     * @return array<int, array<int, HintCall>>
     */
    public static function latestCalls(RoundState $state): array
    {
        $calls = [];
        foreach ($state->history as $h) {
            if ($h->call === null || $h->callNumber === null) {
                continue;
            }
            $call = HintCall::tryFrom($h->call);
            if ($call !== null && $call !== HintCall::HugIt) {
                $calls[$h->player][$h->callNumber] = $call;
            }
        }

        return $calls;
    }

    private static function hasCalled(RoundState $state, int $player, int $n, HintCall $call): bool
    {
        foreach ($state->history as $h) {
            if ($h->player === $player && $h->call === $call->value && $h->callNumber === $n) {
                return true;
            }
        }

        return false;
    }

    /** @param Tile[] $tiles */
    private static function countWith(array $tiles, int $n): int
    {
        return count(array_filter($tiles, fn (Tile $t) => GameEngine::tileHas($t, $n)));
    }
}
