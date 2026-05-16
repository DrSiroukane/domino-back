<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Services\Game\Data\BoardEntryPlayed;
use App\Services\Game\Data\BoardEntryStart;
use App\Services\Game\Data\HistoryEntry;
use App\Services\Game\Data\MatchConfig;
use App\Services\Game\Data\MatchState;
use App\Services\Game\Data\RoundResult;
use App\Services\Game\Data\RoundResultBlocked;
use App\Services\Game\Data\RoundResultDomino;
use App\Services\Game\Data\RoundState;
use App\Services\Game\Data\Tile;

/**
 * Pure game-logic engine — PHP port of domino-front/lib/engine.ts.
 *
 * All methods are stateless and return new state objects (no side-effects).
 */
final class GameEngine
{
    public const int CAPICUA_BONUS = 30;

    // ------------------------------------------------------------------ //
    // Tile helpers
    // ------------------------------------------------------------------ //

    /** Builds the canonical double-six set (28 tiles, id 0–27). */
    public static function makeFullSet(): array
    {
        $tiles = [];
        $id = 0;
        for ($a = 0; $a <= 6; $a++) {
            for ($b = $a; $b <= 6; $b++) {
                $tiles[] = new Tile($a, $b, $id++);
            }
        }

        return $tiles;
    }

    public static function isDouble(Tile $t): bool
    {
        return $t->a === $t->b;
    }

    public static function tilePips(Tile $t): int
    {
        return $t->a + $t->b;
    }

    public static function tileHas(Tile $t, int $n): bool
    {
        return $t->a === $n || $t->b === $n;
    }

    /** @param Tile[] $hand */
    public static function handPips(array $hand): int
    {
        $sum = 0;
        foreach ($hand as $t) {
            $sum += self::tilePips($t);
        }

        return $sum;
    }

    // ------------------------------------------------------------------ //
    // Hand validation
    // ------------------------------------------------------------------ //

    /**
     * A hand is invalid if it contains 5+ doubles or 6+ tiles of the same suit.
     *
     * @param  Tile[]  $hand
     */
    public static function handIsValid(array $hand): bool
    {
        $doubles = 0;
        foreach ($hand as $t) {
            if (self::isDouble($t)) {
                $doubles++;
            }
        }
        if ($doubles >= 5) {
            return false;
        }

        $suits = array_fill(0, 7, 0);
        foreach ($hand as $t) {
            $suits[$t->a]++;
            if ($t->a !== $t->b) {
                $suits[$t->b]++;
            }
        }
        foreach ($suits as $count) {
            if ($count >= 6) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ //
    // Shuffle & deal
    // ------------------------------------------------------------------ //

    /**
     * Fisher-Yates in-place shuffle — returns a new array.
     *
     * @param  Tile[]  $arr
     * @return Tile[]
     */
    public static function shuffle(array $arr): array
    {
        $a = array_values($arr);
        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }

        return $a;
    }

    /**
     * Deals 7 tiles per player from a shuffled full set.
     * Retries up to 200 times to get valid hands, then falls back without validation.
     *
     * @return array{hands: Tile[][], boneyard: Tile[]}
     */
    public static function deal(int $numPlayers): array
    {
        $handSize = 7;

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $tiles = self::shuffle(self::makeFullSet());
            $hands = [];
            for ($i = 0; $i < $numPlayers; $i++) {
                $hands[] = array_slice($tiles, $i * $handSize, $handSize);
            }
            $boneyard = array_slice($tiles, $numPlayers * $handSize);

            $allValid = true;
            foreach ($hands as $hand) {
                if (! self::handIsValid($hand)) {
                    $allValid = false;
                    break;
                }
            }
            if ($allValid) {
                return ['hands' => $hands, 'boneyard' => $boneyard];
            }
        }

        // Fallback: deal without validation
        $tiles = self::shuffle(self::makeFullSet());
        $hands = [];
        for ($i = 0; $i < $numPlayers; $i++) {
            $hands[] = array_slice($tiles, $i * $handSize, $handSize);
        }

        return ['hands' => $hands, 'boneyard' => array_slice($tiles, $numPlayers * $handSize)];
    }

    // ------------------------------------------------------------------ //
    // First-mover logic
    // ------------------------------------------------------------------ //

    /**
     * Finds who starts: highest double (6-6 down to 0-0), or highest pip count.
     *
     * @param  Tile[][]  $hands
     * @return array{player: int, tile: array{a:int,b:int}|null}
     */
    public static function findHighestDoubleStarter(array $hands): array
    {
        for ($v = 6; $v >= 0; $v--) {
            foreach ($hands as $p => $hand) {
                foreach ($hand as $t) {
                    if ($t->a === $v && $t->b === $v) {
                        return ['player' => $p, 'tile' => ['a' => $v, 'b' => $v]];
                    }
                }
            }
        }

        // No doubles found — use highest pip count
        $bestPlayer = 0;
        $bestPips = -1;
        $bestTile = null;
        foreach ($hands as $p => $hand) {
            foreach ($hand as $t) {
                $pips = self::tilePips($t);
                if ($pips > $bestPips) {
                    $bestPips = $pips;
                    $bestPlayer = $p;
                    $bestTile = $t;
                }
            }
        }

        return [
            'player' => $bestPlayer,
            'tile' => $bestTile !== null ? ['a' => $bestTile->a, 'b' => $bestTile->b] : null,
        ];
    }

    // ------------------------------------------------------------------ //
    // Initialisation
    // ------------------------------------------------------------------ //

    public static function initRound(MatchConfig $config, ?int $lastWinner = null): RoundState
    {
        ['hands' => $hands, 'boneyard' => $boneyard] = self::deal($config->numPlayers);

        if ($lastWinner !== null) {
            $firstMover = $lastWinner;
            $mandatoryFirstTile = null;
        } else {
            $start = self::findHighestDoubleStarter($hands);
            $firstMover = $start['player'];
            $mandatoryFirstTile = $start['tile'];
        }

        return new RoundState(
            numPlayers: $config->numPlayers,
            teams: $config->teams,
            hands: $hands,
            board: [],
            leftEnd: null,
            rightEnd: null,
            boneyard: $boneyard,
            currentPlayer: $firstMover,
            firstMover: $firstMover,
            mandatoryFirstTile: $mandatoryFirstTile,
            passes: 0,
            roundOver: false,
            history: [],
            roundResult: null,
        );
    }

    public static function initMatch(MatchConfig $config): MatchState
    {
        $scores = $config->teams
            ? [0, 0]
            : array_fill(0, $config->numPlayers, 0);

        return new MatchState(
            config: $config,
            round: self::initRound($config),
            scores: $scores,
            matchOver: false,
            matchWinner: null,
            lastWinner: null,
            roundsPlayed: 0,
        );
    }

    // ------------------------------------------------------------------ //
    // Move legality
    // ------------------------------------------------------------------ //

    /**
     * Returns which sides a tile may legally be played on.
     *
     * @return list<'left'|'right'|'start'>
     */
    public static function legalSidesForTile(RoundState $state, Tile $tile): array
    {
        if (count($state->board) === 0) {
            return ['start'];
        }

        $sides = [];
        if ($state->leftEnd !== null && self::tileHas($tile, $state->leftEnd)) {
            $sides[] = 'left';
        }
        if ($state->rightEnd !== null && self::tileHas($tile, $state->rightEnd)) {
            $sides[] = 'right';
        }

        return $sides;
    }

    /** @param Tile[] $hand */
    public static function canPlayAnyTile(RoundState $state, array $hand): bool
    {
        foreach ($hand as $t) {
            if (count(self::legalSidesForTile($state, $t)) > 0) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ //
    // State transitions
    // ------------------------------------------------------------------ //

    /**
     * Plays a tile from `$playerIdx`'s hand onto the board.
     * Returns an updated RoundState (original is not mutated).
     */
    public static function playTile(
        RoundState $state,
        int $playerIdx,
        int $tileId,
        string $side,   // 'left' | 'right' | 'start'
    ): RoundState {
        // Build independent copies of the mutable arrays
        $newHands = array_map(fn (array $h) => array_values($h), $state->hands);
        $newBoard = array_values($state->board);
        $newHistory = array_values($state->history);

        // Find the tile to play
        $tIdx = null;
        $tile = null;
        foreach ($newHands[$playerIdx] as $i => $t) {
            if ($t->id === $tileId) {
                $tIdx = $i;
                $tile = $t;
                break;
            }
        }
        if ($tIdx === null) {
            // Tile not found — return state copy unchanged
            $s = clone $state;
            $s->hands = $newHands;
            $s->board = $newBoard;
            $s->history = $newHistory;

            return $s;
        }

        // Capicúa eligibility: last tile that fits on both open ends simultaneously.
        // Computed on the original (pre-play) state.
        $capicuaEligible = count($state->board) > 0
            && count(self::legalSidesForTile($state, $tile)) === 2;

        // Remove from hand
        array_splice($newHands[$playerIdx], $tIdx, 1);

        // Place on board
        $newLeftEnd = $state->leftEnd;
        $newRightEnd = $state->rightEnd;

        if (count($newBoard) === 0) {
            $newBoard[] = new BoardEntryStart($tile, $tile->a, $tile->b);
            $newLeftEnd = $tile->a;
            $newRightEnd = $tile->b;
        } elseif ($side === 'left') {
            $matchEnd = $state->leftEnd;
            $newExposed = ($tile->a === $matchEnd) ? $tile->b : $tile->a;
            array_unshift($newBoard, new BoardEntryPlayed($tile, 'left', $matchEnd, $newExposed));
            $newLeftEnd = $newExposed;
        } else { // 'right'
            $matchEnd = $state->rightEnd;
            $newExposed = ($tile->a === $matchEnd) ? $tile->b : $tile->a;
            $newBoard[] = new BoardEntryPlayed($tile, 'right', $matchEnd, $newExposed);
            $newRightEnd = $newExposed;
        }

        $newHistory[] = new HistoryEntry($playerIdx, 'play', $tile, $side);

        $s = clone $state;
        $s->hands = $newHands;
        $s->board = $newBoard;
        $s->leftEnd = $newLeftEnd;
        $s->rightEnd = $newRightEnd;
        $s->passes = 0;
        $s->history = $newHistory;
        $s->mandatoryFirstTile = null;
        $s->currentPlayer = ($playerIdx + 1) % $state->numPlayers;

        if (count($newHands[$playerIdx]) === 0) {
            $s->roundOver = true;
            $s->roundResult = self::computeRoundResult(
                $s,
                $playerIdx,
                'domino',
                ['capicua' => $capicuaEligible],
            );
        }

        return $s;
    }

    /**
     * Records a pass for `$playerIdx`.
     * If all players have passed consecutively the round is marked blocked.
     *
     * @param  array{blockedTiebreak?: string}  $opts
     */
    public static function passTurn(RoundState $state, int $playerIdx, array $opts = []): RoundState
    {
        $s = clone $state;
        $s->history = array_values($state->history);

        $s->history[] = new HistoryEntry($playerIdx, 'pass');
        $s->passes = $state->passes + 1;
        $s->currentPlayer = ($playerIdx + 1) % $state->numPlayers;

        if ($s->passes >= $state->numPlayers) {
            $s->roundOver = true;
            $s->roundResult = self::computeRoundResult($s, null, 'blocked', $opts);
        }

        return $s;
    }

    /**
     * Draws the top tile from the boneyard into `$playerIdx`'s hand.
     * Returns state unchanged if boneyard is empty.
     */
    public static function drawTile(RoundState $state, int $playerIdx): RoundState
    {
        if (count($state->boneyard) === 0) {
            return $state;
        }

        $s = clone $state;
        $s->hands = array_map(fn (array $h) => array_values($h), $state->hands);
        $s->boneyard = array_values($state->boneyard);
        $s->history = array_values($state->history);

        $tile = array_shift($s->boneyard);
        $s->hands[$playerIdx][] = $tile;
        $s->history[] = new HistoryEntry($playerIdx, 'draw');

        return $s;
    }

    // ------------------------------------------------------------------ //
    // Scoring
    // ------------------------------------------------------------------ //

    /**
     * Computes the result of a finished round.
     *
     * @param  array{capicua?: bool, blockedTiebreak?: string}  $opts
     */
    public static function computeRoundResult(
        RoundState $state,
        ?int $dominoer,
        string $kind,
        array $opts = [],
    ): RoundResult {
        $handTotals = array_map(fn (array $hand) => self::handPips($hand), $state->hands);
        $capicua = (bool) ($opts['capicua'] ?? false);

        // ---- Domino (someone emptied their hand) ----
        if ($kind === 'domino' && $dominoer !== null) {
            if ($state->teams) {
                $winningTeam = $dominoer % 2;
                $pts = 0;
                for ($p = 0; $p < $state->numPlayers; $p++) {
                    if ($p % 2 !== $winningTeam) {
                        $pts += $handTotals[$p];
                    }
                }
                if ($capicua) {
                    $pts += self::CAPICUA_BONUS;
                }

                return new RoundResultDomino(
                    winner: $dominoer,
                    points: $pts,
                    handTotals: $handTotals,
                    capicua: $capicua,
                    capicuaBonus: $capicua ? self::CAPICUA_BONUS : 0,
                    winningTeam: $winningTeam,
                );
            }

            $pts = 0;
            for ($p = 0; $p < $state->numPlayers; $p++) {
                if ($p !== $dominoer) {
                    $pts += $handTotals[$p];
                }
            }
            if ($capicua) {
                $pts += self::CAPICUA_BONUS;
            }

            return new RoundResultDomino(
                winner: $dominoer,
                points: $pts,
                handTotals: $handTotals,
                capicua: $capicua,
                capicuaBonus: $capicua ? self::CAPICUA_BONUS : 0,
            );
        }

        // ---- Blocked (all players passed) ----
        if ($state->teams) {
            $tiebreak = $opts['blockedTiebreak'] ?? 'sum';
            $team0Total = $handTotals[0] + $handTotals[2];
            $team1Total = $handTotals[1] + $handTotals[3];

            if ($tiebreak === 'low-player') {
                $team0LowIdx = $handTotals[0] <= $handTotals[2] ? 0 : 2;
                $team1LowIdx = $handTotals[1] <= $handTotals[3] ? 1 : 3;
                $team0Min = $handTotals[$team0LowIdx];
                $team1Min = $handTotals[$team1LowIdx];

                if ($team0Min < $team1Min) {
                    return new RoundResultBlocked(
                        points: $team1Total,
                        handTotals: $handTotals,
                        winningTeam: 0,
                        team0: $team0Total,
                        team1: $team1Total,
                        team0Min: $team0Min,
                        team1Min: $team1Min,
                        lowPlayer: $team0LowIdx,
                        tiebreak: $tiebreak,
                    );
                }
                if ($team1Min < $team0Min) {
                    return new RoundResultBlocked(
                        points: $team0Total,
                        handTotals: $handTotals,
                        winningTeam: 1,
                        team0: $team0Total,
                        team1: $team1Total,
                        team0Min: $team0Min,
                        team1Min: $team1Min,
                        lowPlayer: $team1LowIdx,
                        tiebreak: $tiebreak,
                    );
                }

                return new RoundResultBlocked(
                    points: 0,
                    handTotals: $handTotals,
                    winningTeam: null,
                    team0: $team0Total,
                    team1: $team1Total,
                    team0Min: $team0Min,
                    team1Min: $team1Min,
                    tiebreak: $tiebreak,
                    tied: true,
                );
            }

            // sum tiebreak (default)
            if ($team0Total < $team1Total) {
                return new RoundResultBlocked(
                    points: $team1Total,
                    handTotals: $handTotals,
                    winningTeam: 0,
                    team0: $team0Total,
                    team1: $team1Total,
                    tiebreak: $tiebreak,
                );
            }
            if ($team1Total < $team0Total) {
                return new RoundResultBlocked(
                    points: $team0Total,
                    handTotals: $handTotals,
                    winningTeam: 1,
                    team0: $team0Total,
                    team1: $team1Total,
                    tiebreak: $tiebreak,
                );
            }

            return new RoundResultBlocked(
                points: 0,
                handTotals: $handTotals,
                winningTeam: null,
                team0: $team0Total,
                team1: $team1Total,
                tiebreak: $tiebreak,
                tied: true,
            );
        }

        // Individual (non-teams) blocked
        $min = min($handTotals);
        $winners = array_keys(array_filter($handTotals, fn (int $t) => $t === $min));

        if (count($winners) === 1) {
            $winner = $winners[0];
            $pts = 0;
            foreach ($handTotals as $i => $t) {
                if ($i !== $winner) {
                    $pts += $t;
                }
            }

            return new RoundResultBlocked(winner: $winner, points: $pts, handTotals: $handTotals);
        }

        return new RoundResultBlocked(winner: null, points: 0, handTotals: $handTotals, tied: true);
    }

    // ------------------------------------------------------------------ //
    // Match state management
    // ------------------------------------------------------------------ //

    /**
     * Applies the finished round's result to the match scores.
     * Determines lastWinner (next round's first mover) and checks if the match is over.
     */
    public static function applyRoundResult(MatchState $match): MatchState
    {
        $r = $match->round->roundResult;
        if ($r === null) {
            return $match;
        }

        $scores = $match->scores;
        $lastWinner = null;

        if ($match->config->teams) {
            $winningTeam = $r->getWinningTeam();
            if ($winningTeam !== null) {
                $scores[$winningTeam] += $r->points;
                $winner = $r->getWinner();
                if ($winner !== null) {
                    // Domino: the player who placed the last tile starts next
                    $lastWinner = $winner;
                } elseif ($r instanceof RoundResultBlocked && $r->lowPlayer !== null) {
                    // Blocked low-player tiebreak: that player starts next
                    $lastWinner = $r->lowPlayer;
                } else {
                    // Blocked sum tiebreak or tie: first mover starts again
                    $lastWinner = $match->round->firstMover;
                }
            } else {
                $lastWinner = $match->round->firstMover;
            }
        } else {
            $winner = $r->getWinner();
            if ($winner !== null) {
                $scores[$winner] += $r->points;
                $lastWinner = $winner;
            } else {
                $lastWinner = $match->round->firstMover;
            }
        }

        $matchOver = false;
        foreach ($scores as $score) {
            if ($score >= $match->config->target) {
                $matchOver = true;
                break;
            }
        }

        $matchWinner = null;
        if ($matchOver) {
            $max = -1;
            foreach ($scores as $i => $score) {
                if ($score > $max) {
                    $max = $score;
                    $matchWinner = $i;
                }
            }
        }

        $updated = clone $match;
        $updated->scores = $scores;
        $updated->matchOver = $matchOver;
        $updated->matchWinner = $matchWinner;
        $updated->lastWinner = $lastWinner;
        $updated->roundsPlayed = $match->roundsPlayed + 1;
        $updated->lastRoundResult = $match->round->roundResult !== null
            ? array_merge($match->round->roundResult->toArray(), ['previousScores' => $match->scores])
            : null;

        return $updated;
    }

    /** Starts the next round, using `$match->lastWinner` as the first mover if set. */
    public static function startNextRound(MatchState $match): MatchState
    {
        $updated = clone $match;
        $updated->round = self::initRound($match->config, $match->lastWinner);

        return $updated;
    }
}
