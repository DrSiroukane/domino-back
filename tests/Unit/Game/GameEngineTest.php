<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Services\Game\Data\BoardEntryPlayed;
use App\Services\Game\Data\BoardEntryStart;
use App\Services\Game\Data\MatchConfig;
use App\Services\Game\Data\MatchState;
use App\Services\Game\Data\RoundResultBlocked;
use App\Services\Game\Data\RoundResultDomino;
use App\Services\Game\Data\RoundState;
use App\Services\Game\Data\Tile;
use App\Services\Game\Enums\BlockedTiebreak;
use App\Services\Game\Enums\Difficulty;
use App\Services\Game\Enums\Opponents;
use App\Services\Game\GameEngine;
use Tests\TestCase;

class GameEngineTest extends TestCase
{
    // ------------------------------------------------------------------ //
    // Helpers
    // ------------------------------------------------------------------ //

    private function makeConfig(
        int $numPlayers = 2,
        bool $teams = false,
        int $target = 100,
        string $tiebreak = 'sum',
    ): MatchConfig {
        return new MatchConfig(
            numPlayers: $numPlayers,
            teams: $teams,
            opponents: Opponents::Hotseat,
            difficulty: Difficulty::Easy,
            target: $target,
            blockedTiebreak: BlockedTiebreak::from($tiebreak),
        );
    }

    /** Returns a minimal RoundState with two players, empty board. */
    private function makeRound(int $numPlayers = 2, bool $teams = false): RoundState
    {
        return GameEngine::initRound($this->makeConfig($numPlayers, $teams));
    }

    /** Builds a tile by (a, b). id is a+b*10 for uniqueness in tests. */
    private function t(int $a, int $b): Tile
    {
        return new Tile($a, $b, $a + $b * 10);
    }

    // ------------------------------------------------------------------ //
    // makeFullSet
    // ------------------------------------------------------------------ //

    public function test_make_full_set_returns_28_tiles(): void
    {
        $set = GameEngine::makeFullSet();
        $this->assertCount(28, $set);
    }

    public function test_make_full_set_ids_are_sequential_0_to_27(): void
    {
        $set = GameEngine::makeFullSet();
        $ids = array_map(fn (Tile $t) => $t->id, $set);
        $this->assertSame(range(0, 27), $ids);
    }

    public function test_make_full_set_contains_all_unique_pip_pairs(): void
    {
        $set = GameEngine::makeFullSet();
        $pairs = array_map(fn (Tile $t) => "{$t->a}-{$t->b}", $set);
        $this->assertCount(28, array_unique($pairs));
    }

    public function test_make_full_set_all_tiles_a_le_b(): void
    {
        foreach (GameEngine::makeFullSet() as $t) {
            $this->assertLessThanOrEqual($t->b, $t->a);
        }
    }

    // ------------------------------------------------------------------ //
    // Tile helpers
    // ------------------------------------------------------------------ //

    public function test_is_double_true_for_same_pips(): void
    {
        $this->assertTrue(GameEngine::isDouble(new Tile(3, 3, 0)));
        $this->assertTrue(GameEngine::isDouble(new Tile(0, 0, 0)));
        $this->assertTrue(GameEngine::isDouble(new Tile(6, 6, 0)));
    }

    public function test_is_double_false_for_different_pips(): void
    {
        $this->assertFalse(GameEngine::isDouble(new Tile(2, 3, 0)));
        $this->assertFalse(GameEngine::isDouble(new Tile(0, 6, 0)));
    }

    public function test_tile_pips_returns_sum(): void
    {
        $this->assertSame(5, GameEngine::tilePips(new Tile(2, 3, 0)));
        $this->assertSame(12, GameEngine::tilePips(new Tile(6, 6, 0)));
        $this->assertSame(0, GameEngine::tilePips(new Tile(0, 0, 0)));
    }

    public function test_tile_has_returns_true_when_value_matches_a_or_b(): void
    {
        $t = new Tile(3, 5, 0);
        $this->assertTrue(GameEngine::tileHas($t, 3));
        $this->assertTrue(GameEngine::tileHas($t, 5));
        $this->assertFalse(GameEngine::tileHas($t, 4));
    }

    public function test_hand_pips_sums_all_tile_pips(): void
    {
        $hand = [new Tile(1, 2, 0), new Tile(3, 4, 0), new Tile(0, 0, 0)];
        $this->assertSame(10, GameEngine::handPips($hand));
    }

    public function test_hand_pips_empty_hand_is_zero(): void
    {
        $this->assertSame(0, GameEngine::handPips([]));
    }

    // ------------------------------------------------------------------ //
    // handIsValid
    // ------------------------------------------------------------------ //

    public function test_hand_is_valid_accepts_normal_hand(): void
    {
        $hand = [
            new Tile(0, 1, 0), new Tile(1, 2, 0), new Tile(2, 3, 0),
            new Tile(3, 4, 0), new Tile(4, 5, 0), new Tile(5, 6, 0),
            new Tile(0, 6, 0),
        ];
        $this->assertTrue(GameEngine::handIsValid($hand));
    }

    public function test_hand_is_valid_rejects_five_or_more_doubles(): void
    {
        $hand = [
            new Tile(0, 0, 0), new Tile(1, 1, 1), new Tile(2, 2, 2),
            new Tile(3, 3, 3), new Tile(4, 4, 4),
            new Tile(0, 1, 5), new Tile(1, 2, 6),
        ];
        $this->assertFalse(GameEngine::handIsValid($hand));
    }

    public function test_hand_is_valid_rejects_six_or_more_tiles_of_same_suit(): void
    {
        // Suit 1 appears 6 times (1 counts in a non-double tile)
        $hand = [
            new Tile(1, 0, 0), new Tile(1, 2, 1), new Tile(1, 3, 2),
            new Tile(1, 4, 3), new Tile(1, 5, 4), new Tile(1, 6, 5),
            new Tile(0, 0, 6),
        ];
        $this->assertFalse(GameEngine::handIsValid($hand));
    }

    public function test_hand_is_valid_four_doubles_is_valid(): void
    {
        $hand = [
            new Tile(0, 0, 0), new Tile(1, 1, 1), new Tile(2, 2, 2),
            new Tile(3, 3, 3),
            new Tile(0, 1, 4), new Tile(2, 4, 5), new Tile(5, 6, 6),
        ];
        $this->assertTrue(GameEngine::handIsValid($hand));
    }

    // ------------------------------------------------------------------ //
    // shuffle
    // ------------------------------------------------------------------ //

    public function test_shuffle_returns_same_length(): void
    {
        $set = GameEngine::makeFullSet();
        $shuffled = GameEngine::shuffle($set);
        $this->assertCount(28, $shuffled);
    }

    public function test_shuffle_contains_same_elements(): void
    {
        $set = GameEngine::makeFullSet();
        $shuffled = GameEngine::shuffle($set);
        $origIds = array_map(fn (Tile $t) => $t->id, $set);
        $shufIds = array_map(fn (Tile $t) => $t->id, $shuffled);
        sort($origIds);
        sort($shufIds);
        $this->assertSame($origIds, $shufIds);
    }

    public function test_shuffle_does_not_modify_original(): void
    {
        $set = GameEngine::makeFullSet();
        $first = $set[0]->id;
        GameEngine::shuffle($set);
        $this->assertSame($first, $set[0]->id);
    }

    // ------------------------------------------------------------------ //
    // deal
    // ------------------------------------------------------------------ //

    public function test_deal_two_players_gives_correct_sizes(): void
    {
        ['hands' => $hands, 'boneyard' => $boneyard] = GameEngine::deal(2);
        $this->assertCount(2, $hands);
        $this->assertCount(7, $hands[0]);
        $this->assertCount(7, $hands[1]);
        $this->assertCount(14, $boneyard);
    }

    public function test_deal_four_players_gives_correct_sizes(): void
    {
        ['hands' => $hands, 'boneyard' => $boneyard] = GameEngine::deal(4);
        $this->assertCount(4, $hands);
        foreach ($hands as $hand) {
            $this->assertCount(7, $hand);
        }
        $this->assertCount(0, $boneyard);
    }

    public function test_deal_all_28_tiles_accounted_for(): void
    {
        ['hands' => $hands, 'boneyard' => $boneyard] = GameEngine::deal(2);
        $all = array_merge(array_merge(...$hands), $boneyard);
        $ids = array_map(fn (Tile $t) => $t->id, $all);
        sort($ids);
        $this->assertSame(range(0, 27), $ids);
    }

    public function test_deal_no_duplicate_tiles(): void
    {
        ['hands' => $hands, 'boneyard' => $boneyard] = GameEngine::deal(4);
        $all = array_merge(array_merge(...$hands), $boneyard);
        $ids = array_map(fn (Tile $t) => $t->id, $all);
        $this->assertCount(28, array_unique($ids));
    }

    // ------------------------------------------------------------------ //
    // findHighestDoubleStarter
    // ------------------------------------------------------------------ //

    public function test_find_highest_double_starter_returns_double_6_6(): void
    {
        $hands = [
            [new Tile(0, 1, 0), new Tile(2, 3, 1)],
            [new Tile(6, 6, 27), new Tile(0, 0, 2)],
        ];
        $result = GameEngine::findHighestDoubleStarter($hands);
        $this->assertSame(1, $result['player']);
        $this->assertSame(['a' => 6, 'b' => 6], $result['tile']);
    }

    public function test_find_highest_double_starter_prefers_higher_double(): void
    {
        $hands = [
            [new Tile(5, 5, 20)],
            [new Tile(4, 4, 14)],
        ];
        $result = GameEngine::findHighestDoubleStarter($hands);
        $this->assertSame(0, $result['player']);
        $this->assertSame(['a' => 5, 'b' => 5], $result['tile']);
    }

    public function test_find_highest_double_starter_falls_back_to_highest_pips(): void
    {
        // No doubles — hand 1 has 6+5=11, hand 0 has 3+4=7
        $hands = [
            [new Tile(3, 4, 0)],
            [new Tile(5, 6, 1)],
        ];
        $result = GameEngine::findHighestDoubleStarter($hands);
        $this->assertSame(1, $result['player']);
    }

    // ------------------------------------------------------------------ //
    // initRound
    // ------------------------------------------------------------------ //

    public function test_init_round_creates_correct_structure(): void
    {
        $config = $this->makeConfig(2);
        $round = GameEngine::initRound($config);

        $this->assertCount(2, $round->hands);
        $this->assertCount(7, $round->hands[0]);
        $this->assertCount(7, $round->hands[1]);
        $this->assertCount(14, $round->boneyard);
        $this->assertCount(0, $round->board);
        $this->assertNull($round->leftEnd);
        $this->assertNull($round->rightEnd);
        $this->assertSame(0, $round->passes);
        $this->assertFalse($round->roundOver);
        $this->assertNull($round->roundResult);
    }

    public function test_init_round_last_winner_sets_first_mover_and_no_mandatory_tile(): void
    {
        $config = $this->makeConfig(2);
        $round = GameEngine::initRound($config, 1);

        $this->assertSame(1, $round->firstMover);
        $this->assertSame(1, $round->currentPlayer);
        $this->assertNull($round->mandatoryFirstTile);
    }

    // ------------------------------------------------------------------ //
    // initMatch
    // ------------------------------------------------------------------ //

    public function test_init_match_creates_correct_initial_state(): void
    {
        $config = $this->makeConfig(2);
        $match = GameEngine::initMatch($config);

        $this->assertSame([0, 0], $match->scores);
        $this->assertFalse($match->matchOver);
        $this->assertNull($match->matchWinner);
        $this->assertSame(0, $match->roundsPlayed);
    }

    public function test_init_match_teams_creates_two_scores(): void
    {
        $config = $this->makeConfig(4, true);
        $match = GameEngine::initMatch($config);
        $this->assertCount(2, $match->scores);
    }

    public function test_init_match_individual_creates_one_score_per_player(): void
    {
        $config = $this->makeConfig(4, false);
        $match = GameEngine::initMatch($config);
        $this->assertCount(4, $match->scores);
    }

    // ------------------------------------------------------------------ //
    // legalSidesForTile
    // ------------------------------------------------------------------ //

    public function test_legal_sides_for_tile_empty_board_returns_start(): void
    {
        $round = $this->makeRound();
        $tile = $this->t(3, 5);
        $this->assertSame(['start'], GameEngine::legalSidesForTile($round, $tile));
    }

    public function test_legal_sides_for_tile_non_matching_returns_empty(): void
    {
        $round = clone $this->makeRound();
        $round->board = [new BoardEntryStart($this->t(2, 4), 2, 4)];
        $round->leftEnd = 2;
        $round->rightEnd = 4;

        $this->assertSame([], GameEngine::legalSidesForTile($round, $this->t(0, 1)));
    }

    public function test_legal_sides_for_tile_matches_left_only(): void
    {
        $round = clone $this->makeRound();
        $round->board = [new BoardEntryStart($this->t(2, 4), 2, 4)];
        $round->leftEnd = 2;
        $round->rightEnd = 4;

        $this->assertSame(['left'], GameEngine::legalSidesForTile($round, $this->t(1, 2)));
    }

    public function test_legal_sides_for_tile_matches_right_only(): void
    {
        $round = clone $this->makeRound();
        $round->board = [new BoardEntryStart($this->t(2, 4), 2, 4)];
        $round->leftEnd = 2;
        $round->rightEnd = 4;

        $this->assertSame(['right'], GameEngine::legalSidesForTile($round, $this->t(4, 6)));
    }

    public function test_legal_sides_for_tile_matches_both_sides(): void
    {
        $round = clone $this->makeRound();
        $round->board = [new BoardEntryStart($this->t(3, 3), 3, 3)];
        $round->leftEnd = 3;
        $round->rightEnd = 3;

        $sides = GameEngine::legalSidesForTile($round, $this->t(3, 5));
        $this->assertContains('left', $sides);
        $this->assertContains('right', $sides);
        $this->assertCount(2, $sides);
    }

    // ------------------------------------------------------------------ //
    // canPlayAnyTile
    // ------------------------------------------------------------------ //

    public function test_can_play_any_tile_true_when_hand_has_matching_tile(): void
    {
        $round = clone $this->makeRound();
        $round->board = [new BoardEntryStart($this->t(3, 5), 3, 5)];
        $round->leftEnd = 3;
        $round->rightEnd = 5;

        $hand = [$this->t(0, 1), $this->t(5, 6)];
        $this->assertTrue(GameEngine::canPlayAnyTile($round, $hand));
    }

    public function test_can_play_any_tile_false_when_no_match(): void
    {
        $round = clone $this->makeRound();
        $round->board = [new BoardEntryStart($this->t(3, 5), 3, 5)];
        $round->leftEnd = 3;
        $round->rightEnd = 5;

        $hand = [$this->t(0, 1), $this->t(1, 2)];
        $this->assertFalse(GameEngine::canPlayAnyTile($round, $hand));
    }

    // ------------------------------------------------------------------ //
    // playTile — first tile on empty board
    // ------------------------------------------------------------------ //

    public function test_play_tile_places_first_tile_on_empty_board(): void
    {
        $tile = new Tile(3, 5, 99);
        $round = clone $this->makeRound();
        $round->hands = [[$tile], [new Tile(0, 1, 1)]];
        $round->board = [];

        $result = GameEngine::playTile($round, 0, 99, 'start');

        $this->assertCount(1, $result->board);
        $this->assertInstanceOf(BoardEntryStart::class, $result->board[0]);
        $this->assertSame(3, $result->leftEnd);
        $this->assertSame(5, $result->rightEnd);
        $this->assertNull($result->mandatoryFirstTile);
        $this->assertSame(0, $result->passes);
    }

    public function test_play_tile_advances_current_player(): void
    {
        $tile = new Tile(2, 4, 50);
        $round = clone $this->makeRound();
        $round->currentPlayer = 0;
        $round->hands = [[$tile], [new Tile(0, 1, 1)]];
        $round->board = [];

        $result = GameEngine::playTile($round, 0, 50, 'start');
        $this->assertSame(1, $result->currentPlayer);
    }

    public function test_play_tile_left_updates_left_end(): void
    {
        $first = new Tile(2, 4, 1);
        $second = new Tile(1, 2, 2);

        $round = clone $this->makeRound();
        $round->board = [new BoardEntryStart($first, 2, 4)];
        $round->leftEnd = 2;
        $round->rightEnd = 4;
        $round->hands = [[$second], []];

        $result = GameEngine::playTile($round, 0, 2, 'left');

        $this->assertSame(1, $result->leftEnd); // 1-2 played left, new exposed = 1
        $this->assertSame(4, $result->rightEnd); // unchanged
        $this->assertInstanceOf(BoardEntryPlayed::class, $result->board[0]);
        $this->assertSame('left', $result->board[0]->side);
    }

    public function test_play_tile_right_updates_right_end(): void
    {
        $first = new Tile(2, 4, 1);
        $second = new Tile(4, 6, 2);

        $round = clone $this->makeRound();
        $round->board = [new BoardEntryStart($first, 2, 4)];
        $round->leftEnd = 2;
        $round->rightEnd = 4;
        $round->hands = [[$second], []];

        $result = GameEngine::playTile($round, 0, 2, 'right');

        $this->assertSame(2, $result->leftEnd);  // unchanged
        $this->assertSame(6, $result->rightEnd); // 4-6 played right, new exposed = 6
    }

    public function test_play_tile_removes_tile_from_hand(): void
    {
        $tile = new Tile(2, 4, 10);
        $other = new Tile(0, 1, 11);

        $round = clone $this->makeRound();
        $round->hands = [[$tile, $other], []];
        $round->board = [];

        $result = GameEngine::playTile($round, 0, 10, 'start');

        $this->assertCount(1, $result->hands[0]);
        $this->assertSame(11, $result->hands[0][0]->id);
    }

    public function test_play_tile_records_history_entry(): void
    {
        $tile = new Tile(3, 3, 5);
        $round = clone $this->makeRound();
        $round->hands = [[$tile], []];
        $round->board = [];

        $result = GameEngine::playTile($round, 0, 5, 'start');

        $this->assertCount(1, $result->history);
        $this->assertSame('play', $result->history[0]->action);
        $this->assertSame(0, $result->history[0]->player);
        $this->assertSame('start', $result->history[0]->side);
    }

    public function test_play_tile_domino_sets_round_over(): void
    {
        $tile = new Tile(3, 5, 99);
        $round = clone $this->makeRound();
        $round->board = [new BoardEntryStart(new Tile(3, 4, 1), 3, 4)];
        $round->leftEnd = 3;
        $round->rightEnd = 4;
        $round->hands = [[$tile], [new Tile(0, 1, 2)]];

        $result = GameEngine::playTile($round, 0, 99, 'left');

        $this->assertTrue($result->roundOver);
        $this->assertInstanceOf(RoundResultDomino::class, $result->roundResult);
        $this->assertSame(0, $result->roundResult->winner);
    }

    public function test_play_tile_resets_passes_counter(): void
    {
        $tile = new Tile(2, 4, 10);
        $round = clone $this->makeRound();
        $round->passes = 1;
        $round->hands = [[$tile], []];
        $round->board = [];

        $result = GameEngine::playTile($round, 0, 10, 'start');
        $this->assertSame(0, $result->passes);
    }

    public function test_play_tile_does_not_mutate_original_state(): void
    {
        $tile = new Tile(2, 4, 10);
        $round = clone $this->makeRound();
        $round->hands = [[$tile], []];
        $round->board = [];

        $boardCountBefore = count($round->board);
        $handCountBefore = count($round->hands[0]);

        GameEngine::playTile($round, 0, 10, 'start');

        $this->assertCount($boardCountBefore, $round->board);
        $this->assertCount($handCountBefore, $round->hands[0]);
    }

    // ------------------------------------------------------------------ //
    // capicúa
    // ------------------------------------------------------------------ //

    public function test_play_tile_capicua_eligible_when_last_tile_fits_both_ends(): void
    {
        // Board ends are both 3; playing 3-5 fits both ends → capicuaEligible
        $tile = new Tile(3, 5, 99);
        $round = clone $this->makeRound();
        $round->board = [new BoardEntryStart(new Tile(3, 3, 1), 3, 3)];
        $round->leftEnd = 3;
        $round->rightEnd = 3;
        $round->hands = [[$tile], [new Tile(0, 1, 2)]];

        $result = GameEngine::playTile($round, 0, 99, 'left');

        $this->assertInstanceOf(RoundResultDomino::class, $result->roundResult);
        $this->assertTrue($result->roundResult->capicua);
        $this->assertSame(GameEngine::CAPICUA_BONUS, $result->roundResult->capicuaBonus);
    }

    public function test_play_tile_no_capicua_when_tile_fits_only_one_end(): void
    {
        $tile = new Tile(3, 5, 99);
        $round = clone $this->makeRound();
        $round->board = [new BoardEntryStart(new Tile(3, 4, 1), 3, 4)];
        $round->leftEnd = 3;
        $round->rightEnd = 4;
        $round->hands = [[$tile], [new Tile(0, 1, 2)]];

        $result = GameEngine::playTile($round, 0, 99, 'left');

        $this->assertInstanceOf(RoundResultDomino::class, $result->roundResult);
        $this->assertFalse($result->roundResult->capicua);
        $this->assertSame(0, $result->roundResult->capicuaBonus);
    }

    // ------------------------------------------------------------------ //
    // passTurn
    // ------------------------------------------------------------------ //

    public function test_pass_turn_increments_passes_and_advances_player(): void
    {
        $round = clone $this->makeRound(2);
        $round->currentPlayer = 0;

        $result = GameEngine::passTurn($round, 0);

        $this->assertSame(1, $result->passes);
        $this->assertSame(1, $result->currentPlayer);
    }

    public function test_pass_turn_records_history_entry(): void
    {
        $round = clone $this->makeRound(2);
        $result = GameEngine::passTurn($round, 0);

        $this->assertCount(1, $result->history);
        $this->assertSame('pass', $result->history[0]->action);
        $this->assertSame(0, $result->history[0]->player);
    }

    public function test_pass_turn_all_players_triggers_blocked(): void
    {
        $round = clone $this->makeRound(2);
        $round->passes = 1; // Player 0 already passed

        $result = GameEngine::passTurn($round, 1); // Player 1 passes → both passed

        $this->assertTrue($result->roundOver);
        $this->assertInstanceOf(RoundResultBlocked::class, $result->roundResult);
    }

    public function test_pass_turn_does_not_mutate_original_state(): void
    {
        $round = clone $this->makeRound(2);
        $originalPasses = $round->passes;
        GameEngine::passTurn($round, 0);
        $this->assertSame($originalPasses, $round->passes);
    }

    // ------------------------------------------------------------------ //
    // drawTile
    // ------------------------------------------------------------------ //

    public function test_draw_tile_adds_tile_to_hand_and_removes_from_boneyard(): void
    {
        $drawn = new Tile(5, 6, 20);
        $round = clone $this->makeRound(2);
        $round->boneyard = [$drawn];
        $round->hands = [[], []];

        $result = GameEngine::drawTile($round, 0);

        $this->assertCount(1, $result->hands[0]);
        $this->assertSame(20, $result->hands[0][0]->id);
        $this->assertCount(0, $result->boneyard);
    }

    public function test_draw_tile_records_history(): void
    {
        $round = clone $this->makeRound(2);
        $round->boneyard = [new Tile(1, 2, 5)];
        $round->hands = [[], []];

        $result = GameEngine::drawTile($round, 0);

        $this->assertCount(1, $result->history);
        $this->assertSame('draw', $result->history[0]->action);
    }

    public function test_draw_tile_returns_same_state_when_boneyard_empty(): void
    {
        $round = clone $this->makeRound(2);
        $round->boneyard = [];

        $result = GameEngine::drawTile($round, 0);
        $this->assertSame($round, $result); // identical object reference
    }

    public function test_draw_tile_does_not_mutate_original_state(): void
    {
        $drawn = new Tile(5, 6, 20);
        $round = clone $this->makeRound(2);
        $round->boneyard = [$drawn];
        $round->hands = [[], []];

        GameEngine::drawTile($round, 0);

        $this->assertCount(1, $round->boneyard);
        $this->assertCount(0, $round->hands[0]);
    }

    // ------------------------------------------------------------------ //
    // computeRoundResult — domino, individual
    // ------------------------------------------------------------------ //

    public function test_compute_round_result_domino_individual_sums_opponents(): void
    {
        $round = clone $this->makeRound(2);
        $round->hands = [[], [new Tile(1, 2, 0), new Tile(3, 4, 1)]]; // winner=0, loser has 10 pips
        $round->numPlayers = 2;
        $round->teams = false;

        $result = GameEngine::computeRoundResult($round, 0, 'domino');

        $this->assertInstanceOf(RoundResultDomino::class, $result);
        $this->assertSame(0, $result->winner);
        $this->assertSame(10, $result->points);
        $this->assertFalse($result->capicua);
    }

    public function test_compute_round_result_domino_individual_capicua_adds_bonus(): void
    {
        $round = clone $this->makeRound(2);
        $round->hands = [[], [new Tile(1, 2, 0)]]; // loser has 3 pips
        $round->numPlayers = 2;
        $round->teams = false;

        $result = GameEngine::computeRoundResult($round, 0, 'domino', ['capicua' => true]);

        $this->assertSame(3 + GameEngine::CAPICUA_BONUS, $result->points);
        $this->assertTrue($result->capicua);
        $this->assertSame(GameEngine::CAPICUA_BONUS, $result->capicuaBonus);
    }

    public function test_compute_round_result_domino_teams_sums_opposing_team(): void
    {
        // 4-player teams: players 0&2 vs 1&3. Player 0 dominoes.
        $round = clone $this->makeRound(4, true);
        $round->hands = [
            [],                              // winner (0) — empty
            [new Tile(1, 2, 1)],             // opponent team1 (3 pips)
            [new Tile(0, 0, 2)],             // own team (not counted)
            [new Tile(4, 5, 3)],             // opponent team1 (9 pips)
        ];
        $round->numPlayers = 4;
        $round->teams = true;

        $result = GameEngine::computeRoundResult($round, 0, 'domino');

        $this->assertInstanceOf(RoundResultDomino::class, $result);
        $this->assertSame(0, $result->winningTeam);
        $this->assertSame(12, $result->points); // pips of players 1 and 3
    }

    // ------------------------------------------------------------------ //
    // computeRoundResult — blocked, individual
    // ------------------------------------------------------------------ //

    public function test_compute_round_result_blocked_individual_min_hand_wins(): void
    {
        $round = clone $this->makeRound(2);
        $round->hands = [
            [new Tile(0, 1, 0)],             // 1 pip
            [new Tile(3, 4, 1), new Tile(1, 2, 2)], // 10 pips
        ];
        $round->teams = false;
        $round->numPlayers = 2;

        $result = GameEngine::computeRoundResult($round, null, 'blocked');

        $this->assertInstanceOf(RoundResultBlocked::class, $result);
        $this->assertSame(0, $result->winner);
        $this->assertSame(10, $result->points);
        $this->assertFalse($result->tied);
    }

    public function test_compute_round_result_blocked_individual_tie(): void
    {
        $round = clone $this->makeRound(2);
        $round->hands = [
            [new Tile(1, 2, 0)],  // 3 pips
            [new Tile(0, 3, 1)],  // 3 pips
        ];
        $round->teams = false;
        $round->numPlayers = 2;

        $result = GameEngine::computeRoundResult($round, null, 'blocked');

        $this->assertNull($result->winner);
        $this->assertSame(0, $result->points);
        $this->assertTrue($result->tied);
    }

    // ------------------------------------------------------------------ //
    // computeRoundResult — blocked, teams
    // ------------------------------------------------------------------ //

    public function test_compute_round_result_blocked_teams_sum_lower_wins(): void
    {
        $round = clone $this->makeRound(4, true);
        $round->hands = [
            [new Tile(1, 0, 0)],  // p0: 1 pip  (team0 total = 3)
            [new Tile(4, 5, 1)],  // p1: 9 pips (team1 total = 15)
            [new Tile(0, 2, 2)],  // p2: 2 pips
            [new Tile(2, 4, 3)],  // p3: 6 pips
        ];
        $round->teams = true;
        $round->numPlayers = 4;

        $result = GameEngine::computeRoundResult($round, null, 'blocked', ['blockedTiebreak' => 'sum']);

        $this->assertSame(0, $result->getWinningTeam()); // team0 has 3 < 15
        $this->assertSame(15, $result->points);           // team1 total
        $this->assertSame('sum', $result->tiebreak);
    }

    public function test_compute_round_result_blocked_teams_sum_tie(): void
    {
        $round = clone $this->makeRound(4, true);
        $round->hands = [
            [new Tile(1, 2, 0)],  // p0: 3  (team0 = 5)
            [new Tile(0, 1, 1)],  // p1: 1  (team1 = 5)
            [new Tile(0, 2, 2)],  // p2: 2
            [new Tile(2, 2, 3)],  // p3: 4
        ];
        $round->teams = true;
        $round->numPlayers = 4;

        $result = GameEngine::computeRoundResult($round, null, 'blocked', ['blockedTiebreak' => 'sum']);

        $this->assertNull($result->getWinningTeam());
        $this->assertSame(0, $result->points);
        $this->assertTrue($result->tied);
    }

    public function test_compute_round_result_blocked_teams_low_player(): void
    {
        $round = clone $this->makeRound(4, true);
        $round->hands = [
            [new Tile(0, 1, 0)],  // p0: 1  ← team0 low player (idx 0)
            [new Tile(3, 4, 1)],  // p1: 7  ← team1 low player (idx 1)
            [new Tile(2, 3, 2)],  // p2: 5
            [new Tile(4, 5, 3)],  // p3: 9
        ];
        $round->teams = true;
        $round->numPlayers = 4;

        $result = GameEngine::computeRoundResult($round, null, 'blocked', ['blockedTiebreak' => 'low-player']);

        // team0 min=1 < team1 min=7 → team0 wins
        $this->assertSame(0, $result->getWinningTeam());
        $this->assertSame(0, $result->lowPlayer);
        $this->assertSame('low-player', $result->tiebreak);
    }

    // ------------------------------------------------------------------ //
    // applyRoundResult
    // ------------------------------------------------------------------ //

    public function test_apply_round_result_updates_scores(): void
    {
        $config = $this->makeConfig(2, false, 100);
        $match = GameEngine::initMatch($config);

        $match->round->roundResult = new RoundResultDomino(
            winner: 0, points: 25, handTotals: [0, 25]
        );

        $updated = GameEngine::applyRoundResult($match);

        $this->assertSame(25, $updated->scores[0]);
        $this->assertSame(0, $updated->scores[1]);
        $this->assertSame(1, $updated->roundsPlayed);
        $this->assertSame(0, $updated->lastWinner);
    }

    public function test_apply_round_result_match_over_when_target_reached(): void
    {
        $config = $this->makeConfig(2, false, 100);
        $match = GameEngine::initMatch($config);
        $match->scores = [95, 0];

        $match->round->roundResult = new RoundResultDomino(
            winner: 0, points: 10, handTotals: [0, 10]
        );

        $updated = GameEngine::applyRoundResult($match);

        $this->assertTrue($updated->matchOver);
        $this->assertSame(0, $updated->matchWinner);
    }

    public function test_apply_round_result_not_over_below_target(): void
    {
        $config = $this->makeConfig(2, false, 100);
        $match = GameEngine::initMatch($config);

        $match->round->roundResult = new RoundResultDomino(
            winner: 0, points: 50, handTotals: [0, 50]
        );

        $updated = GameEngine::applyRoundResult($match);

        $this->assertFalse($updated->matchOver);
        $this->assertNull($updated->matchWinner);
    }

    public function test_apply_round_result_tie_sets_first_mover_as_last_winner(): void
    {
        $config = $this->makeConfig(2, false, 100);
        $match = GameEngine::initMatch($config);
        $match->round->firstMover = 1;
        $match->round->roundResult = new RoundResultBlocked(
            points: 0, handTotals: [5, 5], winner: null, tied: true
        );

        $updated = GameEngine::applyRoundResult($match);
        $this->assertSame(1, $updated->lastWinner);
    }

    public function test_apply_round_result_does_not_mutate_original(): void
    {
        $config = $this->makeConfig(2, false, 100);
        $match = GameEngine::initMatch($config);
        $match->round->roundResult = new RoundResultDomino(
            winner: 0, points: 20, handTotals: [0, 20]
        );

        $originalScores = $match->scores;
        GameEngine::applyRoundResult($match);
        $this->assertSame($originalScores, $match->scores);
    }

    // ------------------------------------------------------------------ //
    // startNextRound
    // ------------------------------------------------------------------ //

    public function test_start_next_round_creates_new_round(): void
    {
        $config = $this->makeConfig(2);
        $match = GameEngine::initMatch($config);
        $match->lastWinner = 1;

        $updated = GameEngine::startNextRound($match);

        $this->assertNotSame($match->round, $updated->round);
        $this->assertSame(1, $updated->round->firstMover);
        $this->assertNull($updated->round->mandatoryFirstTile);
    }

    // ------------------------------------------------------------------ //
    // Serialisation round-trip
    // ------------------------------------------------------------------ //

    public function test_tile_serialises_and_deserialises(): void
    {
        $tile = new Tile(3, 5, 12);
        $arr = $tile->toArray();
        $back = Tile::fromArray($arr);

        $this->assertSame($tile->a, $back->a);
        $this->assertSame($tile->b, $back->b);
        $this->assertSame($tile->id, $back->id);
    }

    public function test_match_state_roundtrip_preserves_structure(): void
    {
        $config = $this->makeConfig(2, false, 100);
        $match = GameEngine::initMatch($config);

        $arr = $match->toArray();
        $back = MatchState::fromArray($arr);

        $this->assertSame($match->scores, $back->scores);
        $this->assertSame($match->matchOver, $back->matchOver);
        $this->assertSame($match->roundsPlayed, $back->roundsPlayed);
        $this->assertCount(count($match->round->hands[0]), $back->round->hands[0]);
        $this->assertCount(count($match->round->boneyard), $back->round->boneyard);
    }

    public function test_round_result_domino_roundtrip(): void
    {
        $r = new RoundResultDomino(2, 45, [0, 20, 25], true, 30, 1);
        $arr = $r->toArray();
        $back = RoundResultDomino::fromArray($arr);

        $this->assertSame($r->winner, $back->winner);
        $this->assertSame($r->points, $back->points);
        $this->assertSame($r->capicua, $back->capicua);
        $this->assertSame($r->capicuaBonus, $back->capicuaBonus);
        $this->assertSame($r->winningTeam, $back->winningTeam);
    }

    public function test_round_result_blocked_roundtrip(): void
    {
        $r = new RoundResultBlocked(12, [3, 9], 0, null, null, null, null, null, null, 'sum', false);
        $arr = $r->toArray();
        $back = RoundResultBlocked::fromArray($arr);

        $this->assertSame($r->winner, $back->winner);
        $this->assertSame($r->points, $back->points);
        $this->assertSame($r->tiebreak, $back->tiebreak);
        $this->assertSame($r->tied, $back->tied);
    }
}
