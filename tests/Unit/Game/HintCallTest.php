<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Services\Game\Data\BoardEntryStart;
use App\Services\Game\Data\HistoryEntry;
use App\Services\Game\Data\MatchConfig;
use App\Services\Game\Data\RoundState;
use App\Services\Game\Data\Tile;
use App\Services\Game\Enums\BlockedTiebreak;
use App\Services\Game\Enums\Difficulty;
use App\Services\Game\Enums\HintCall;
use App\Services\Game\Enums\Opponents;
use App\Services\Game\GameEngine;
use App\Services\Game\HintCallAdvisor;
use Tests\TestCase;

class HintCallTest extends TestCase
{
    // ------------------------------------------------------------------ //
    // Helpers
    // ------------------------------------------------------------------ //

    private function config(bool $hintCalls = true, int $numPlayers = 4, bool $teams = true, Difficulty $difficulty = Difficulty::Medium): MatchConfig
    {
        return new MatchConfig(
            numPlayers: $numPlayers,
            teams: $teams,
            opponents: Opponents::Hotseat,
            difficulty: $difficulty,
            target: 100,
            blockedTiebreak: BlockedTiebreak::Sum,
            hintCalls: $hintCalls,
        );
    }

    private function t(int $a, int $b): Tile
    {
        return new Tile($a, $b, $a * 7 + $b);
    }

    /**
     * Seat 0's hand is `$hand`; other seats get filler. With `$opening` the board is empty,
     * otherwise the 6-6 lies on the table (both ends 6).
     *
     * @param  Tile[]  $hand
     * @param  HistoryEntry[]  $history
     */
    private function round(array $hand, bool $opening = false, array $history = []): RoundState
    {
        $filler = [$this->t(0, 0), $this->t(0, 1), $this->t(0, 2)];

        return new RoundState(
            numPlayers: 4,
            teams: true,
            hands: [$hand, $filler, $filler, $filler],
            board: $opening ? [] : [new BoardEntryStart($this->t(6, 6), 6, 6)],
            leftEnd: $opening ? null : 6,
            rightEnd: $opening ? null : 6,
            boneyard: [],
            currentPlayer: 0,
            firstMover: 0,
            mandatoryFirstTile: null,
            passes: 0,
            roundOver: false,
            history: $history,
            roundResult: null,
        );
    }

    // ------------------------------------------------------------------ //
    // legalCalls
    // ------------------------------------------------------------------ //

    public function test_no_calls_when_hint_calls_are_off(): void
    {
        $round = $this->round([$this->t(6, 2), $this->t(1, 1), $this->t(3, 4)]);

        $this->assertSame([], GameEngine::legalCalls($this->config(hintCalls: false), $round, 0, $this->t(6, 2)));
    }

    public function test_no_calls_outside_four_player_teams(): void
    {
        $round = $this->round([$this->t(6, 2), $this->t(1, 1), $this->t(3, 4)]);

        $this->assertSame([], GameEngine::legalCalls($this->config(numPlayers: 2, teams: false), $round, 0, $this->t(6, 2)));
    }

    public function test_opening_double_allows_only_hug_it(): void
    {
        $round = $this->round([$this->t(6, 6), $this->t(1, 1), $this->t(3, 4)], opening: true);

        $this->assertSame([HintCall::HugIt], GameEngine::legalCalls($this->config(), $round, 0, $this->t(6, 6)));
    }

    public function test_opening_non_double_allows_no_call(): void
    {
        $round = $this->round([$this->t(6, 5), $this->t(1, 1), $this->t(3, 4)], opening: true);

        $this->assertSame([], GameEngine::legalCalls($this->config(), $round, 0, $this->t(6, 5)));
    }

    public function test_mid_game_allows_number_calls_and_close_game(): void
    {
        $round = $this->round([$this->t(6, 2), $this->t(1, 1), $this->t(3, 4)]);

        $this->assertSame(
            [HintCall::One, HintCall::Good, HintCall::Dead, HintCall::MyHead, HintCall::CloseGame],
            GameEngine::legalCalls($this->config(), $round, 0, $this->t(6, 2)),
        );
    }

    public function test_no_call_when_play_leaves_one_tile(): void
    {
        $round = $this->round([$this->t(6, 2), $this->t(1, 1)]);

        $this->assertSame([], GameEngine::legalCalls($this->config(), $round, 0, $this->t(6, 2)));
    }

    // ------------------------------------------------------------------ //
    // Recording calls
    // ------------------------------------------------------------------ //

    public function test_play_records_call_with_open_end_number(): void
    {
        $tile = $this->t(6, 2);
        $round = $this->round([$tile, $this->t(1, 1), $this->t(3, 4)]);

        $history = GameEngine::playTile($round, 0, $tile->id, 'right', HintCall::Good)->history;
        $last = end($history);

        $this->assertSame('good', $last->call);
        $this->assertSame(2, $last->callNumber);
        $this->assertSame(['player' => 0, 'action' => 'play', 'tile' => $tile->toArray(), 'side' => 'right', 'call' => 'good', 'callNumber' => 2], $last->toArray());
    }

    public function test_close_game_has_no_number(): void
    {
        $tile = $this->t(6, 2);
        $round = $this->round([$tile, $this->t(1, 1), $this->t(3, 4)]);

        $history = GameEngine::playTile($round, 0, $tile->id, 'left', HintCall::CloseGame)->history;
        $last = end($history);

        $this->assertSame('close_game', $last->call);
        $this->assertNull($last->callNumber);
    }

    public function test_history_without_call_is_unchanged_and_old_entries_load(): void
    {
        $entry = HistoryEntry::fromArray(['player' => 1, 'action' => 'pass']);

        $this->assertNull($entry->call);
        $this->assertSame(['player' => 1, 'action' => 'pass'], $entry->toArray());
    }

    public function test_old_match_config_loads_with_hint_calls_off(): void
    {
        $config = MatchConfig::fromArray([
            'numPlayers' => 4, 'teams' => true, 'opponents' => 'hotseat',
            'difficulty' => 'medium', 'target' => 100,
        ]);

        $this->assertFalse($config->hintCalls);
    }

    // ------------------------------------------------------------------ //
    // Bot advice
    // ------------------------------------------------------------------ //

    public function test_bot_says_hug_it_when_it_cannot_follow_the_double(): void
    {
        $double = $this->t(6, 6);
        $round = $this->round([$double, $this->t(6, 1), $this->t(3, 4)], opening: true);

        $this->assertSame(HintCall::HugIt, HintCallAdvisor::chooseCall($this->config(), $round, 0, $double, 'start'));
    }

    public function test_bot_stays_silent_on_opening_when_strong_in_the_number(): void
    {
        $double = $this->t(6, 6);
        $round = $this->round([$double, $this->t(6, 1), $this->t(6, 4), $this->t(2, 3)], opening: true);

        $this->assertNull(HintCallAdvisor::chooseCall($this->config(), $round, 0, $double, 'start'));
    }

    public function test_bot_calls_good_dead_one_and_my_head_honestly(): void
    {
        $tile = $this->t(6, 2);
        $config = $this->config();

        $good = $this->round([$tile, $this->t(2, 1), $this->t(2, 4), $this->t(5, 5)]);
        $this->assertSame(HintCall::Good, HintCallAdvisor::chooseCall($config, $good, 0, $tile, 'right', 0.99));

        $dead = $this->round([$tile, $this->t(1, 3), $this->t(4, 5), $this->t(5, 5)]);
        $this->assertSame(HintCall::Dead, HintCallAdvisor::chooseCall($config, $dead, 0, $tile, 'right', 0.99));

        $one = $this->round([$tile, $this->t(2, 1), $this->t(4, 5), $this->t(5, 5)]);
        $this->assertSame(HintCall::One, HintCallAdvisor::chooseCall($config, $one, 0, $tile, 'right', 0.99));

        $head = $this->round([$tile, $this->t(2, 2), $this->t(4, 5), $this->t(5, 5)]);
        $this->assertSame(HintCall::MyHead, HintCallAdvisor::chooseCall($config, $head, 0, $tile, 'right', 0.99));
    }

    public function test_bot_may_bluff_dead_with_one_tile_but_easy_bots_never_do(): void
    {
        $tile = $this->t(6, 2);
        $round = $this->round([$tile, $this->t(2, 1), $this->t(4, 5), $this->t(5, 5), $this->t(3, 4)]);

        $this->assertSame(HintCall::Dead, HintCallAdvisor::chooseCall($this->config(difficulty: Difficulty::Hard), $round, 0, $tile, 'right', 0.0));
        $this->assertSame(HintCall::One, HintCallAdvisor::chooseCall($this->config(difficulty: Difficulty::Easy), $round, 0, $tile, 'right', 0.0));
    }

    public function test_bot_keeps_partner_good_number_open(): void
    {
        // Partner (seat 2) called "good" on 3 earlier this round.
        $history = [new HistoryEntry(2, 'play', $this->t(5, 3), 'left', 'good', 3)];
        $round = $this->round([$this->t(6, 3), $this->t(6, 1), $this->t(4, 4)], history: $history);

        $this->assertGreaterThan(
            HintCallAdvisor::scoreMove($round, 0, $this->t(6, 1), 'right'),
            HintCallAdvisor::scoreMove($round, 0, $this->t(6, 3), 'right'),
        );
    }

    public function test_bot_answers_partner_hug_it_by_playing_the_other_side(): void
    {
        $history = [
            new HistoryEntry(2, 'play', $this->t(6, 6), 'start', 'hug_it', 6),
            new HistoryEntry(3, 'play', $this->t(6, 5), 'right'),
        ];
        $round = $this->round([$this->t(6, 1), $this->t(4, 4), $this->t(3, 3)], history: $history);

        $this->assertGreaterThan(
            HintCallAdvisor::scoreMove($round, 0, $this->t(6, 1), 'right'),
            HintCallAdvisor::scoreMove($round, 0, $this->t(6, 1), 'left'),
        );
    }

    // ------------------------------------------------------------------ //
    // Passing: "close the game" call and passed-on numbers
    // ------------------------------------------------------------------ //

    public function test_pass_allows_only_close_the_game(): void
    {
        $round = $this->round([$this->t(1, 2), $this->t(3, 4)]);

        $this->assertSame([HintCall::CloseGame], GameEngine::legalPassCalls($this->config(), $round, 0));
        $this->assertSame([], GameEngine::legalPassCalls($this->config(hintCalls: false), $round, 0));
        $this->assertSame([], GameEngine::legalPassCalls($this->config(numPlayers: 2, teams: false), $round, 0));
    }

    public function test_no_pass_call_on_the_last_tile(): void
    {
        $round = $this->round([$this->t(1, 2)]);

        $this->assertSame([], GameEngine::legalPassCalls($this->config(), $round, 0));
    }

    public function test_no_close_the_game_on_the_pass_that_ends_the_round(): void
    {
        $round = $this->round([$this->t(1, 2), $this->t(3, 4)]);
        $round->passes = 3; // the other three already passed: this pass blocks the round

        $this->assertSame([], GameEngine::legalPassCalls($this->config(), $round, 0));
    }

    public function test_no_close_the_game_when_nobody_can_follow_either_end(): void
    {
        // All seven 6s are on the board and both ends show 6: the game is already closed.
        $round = $this->round([$this->t(1, 2), $this->t(3, 4)]);
        $round->board = array_map(
            fn (int $x) => new BoardEntryStart($this->t(min($x, 6), max($x, 6)), 6, 6),
            [0, 1, 2, 3, 4, 5, 6],
        );

        $this->assertTrue(GameEngine::endsAreDead($round));
        $this->assertSame([], GameEngine::legalPassCalls($this->config(), $round, 0));
    }

    public function test_pass_turn_records_the_call(): void
    {
        $round = $this->round([$this->t(1, 2), $this->t(3, 4)]);

        $after = GameEngine::passTurn($round, 0, [], HintCall::CloseGame);
        $entry = end($after->history);

        $this->assertSame('pass', $entry->action);
        $this->assertSame('close_game', $entry->call);
        $this->assertSame(['player' => 0, 'action' => 'pass', 'call' => 'close_game', 'callNumber' => null], $entry->toArray());
    }

    public function test_passed_numbers_are_the_open_ends_at_each_pass(): void
    {
        $history = [
            new HistoryEntry(0, 'play', $this->t(6, 6), 'start'),
            new HistoryEntry(1, 'pass'),                                 // ends 6 | 6
            new HistoryEntry(2, 'play', $this->t(6, 2), 'right'),        // ends 6 | 2
            new HistoryEntry(3, 'play', $this->t(4, 6), 'left'),         // ends 4 | 2
            new HistoryEntry(0, 'pass'),                                 // ends 4 | 2
            new HistoryEntry(1, 'pass'),                                 // ends 4 | 2
        ];
        $round = $this->round([], history: $history);

        $this->assertSame([1 => [2, 4, 6], 0 => [2, 4]], GameEngine::passedNumbers($round));
    }

    public function test_bot_avoids_leaving_partner_stuck_again(): void
    {
        // Partner (seat 2) passed on 6 | 6.
        $history = [new HistoryEntry(1, 'play', $this->t(6, 6), 'start'), new HistoryEntry(2, 'pass')];
        $round = $this->round([$this->t(6, 1), $this->t(6, 5)], history: $history);
        $round->hands[0][] = new Tile(6, 6, 99); // a second 6-6 only for the test: it would keep 6 | 6 open

        $this->assertGreaterThan(
            HintCallAdvisor::scoreMove($round, 0, new Tile(6, 6, 99), 'right'),
            HintCallAdvisor::scoreMove($round, 0, $this->t(6, 1), 'right'),
        );
    }

    public function test_bot_prefers_leaving_an_opponent_stuck(): void
    {
        // Opponent (seat 1) passed on 6 | 6.
        $history = [new HistoryEntry(3, 'play', $this->t(6, 6), 'start'), new HistoryEntry(1, 'pass')];
        $round = $this->round([$this->t(6, 1)], history: $history);

        $this->assertGreaterThan(
            HintCallAdvisor::scoreMove($round, 0, $this->t(6, 1), 'right'),
            HintCallAdvisor::scoreMove($round, 0, new Tile(6, 6, 99), 'right'),
        );
    }

    public function test_bot_calls_close_the_game_when_passing_with_a_low_hand(): void
    {
        $low = $this->round([$this->t(0, 1), $this->t(0, 2), $this->t(1, 1)]);    // 5 pips
        $high = $this->round([$this->t(5, 5), $this->t(4, 3), $this->t(1, 1)]);   // 19 pips

        $this->assertSame(HintCall::CloseGame, HintCallAdvisor::choosePassCall($this->config(), $low, 0));
        $this->assertNull(HintCallAdvisor::choosePassCall($this->config(), $high, 0));
        $this->assertNull(HintCallAdvisor::choosePassCall($this->config(hintCalls: false), $low, 0));
    }
}
