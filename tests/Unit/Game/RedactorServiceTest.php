<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Services\Game\Data\MatchConfig;
use App\Services\Game\Data\MatchState;
use App\Services\Game\Enums\BlockedTiebreak;
use App\Services\Game\Enums\Difficulty;
use App\Services\Game\Enums\Opponents;
use App\Services\Game\GameEngine;
use App\Services\Game\RedactorService;
use Tests\TestCase;

class RedactorServiceTest extends TestCase
{
    private function makeMatch(int $numPlayers = 2): MatchState
    {
        $config = new MatchConfig(
            numPlayers: $numPlayers,
            teams: false,
            opponents: Opponents::Hotseat,
            difficulty: Difficulty::Easy,
            target: 100,
            blockedTiebreak: BlockedTiebreak::Sum,
        );

        return GameEngine::initMatch($config);
    }

    public function test_own_hand_is_fully_visible(): void
    {
        $match = $this->makeMatch();
        $view = (new RedactorService)->redact($match, 0);

        $this->assertCount(7, $view->myHand);
        foreach ($view->myHand as $tile) {
            $this->assertArrayHasKey('a', $tile);
            $this->assertArrayHasKey('b', $tile);
            $this->assertArrayHasKey('id', $tile);
        }
    }

    public function test_hand_counts_cover_all_seats(): void
    {
        $match = $this->makeMatch(2);
        $view = (new RedactorService)->redact($match, 0);

        $this->assertCount(2, $view->handCounts);
        $this->assertSame(7, $view->handCounts[0]);
        $this->assertSame(7, $view->handCounts[1]);
    }

    public function test_boneyard_count_is_correct_and_tiles_are_hidden(): void
    {
        $match = $this->makeMatch(2);
        $view = (new RedactorService)->redact($match, 0);

        // 28 tiles - 14 dealt (2 players × 7) = 14 in boneyard
        $this->assertSame(14, $view->boneyardCount);
        // ClientView has no 'boneyard' key with actual tiles
        $this->assertArrayNotHasKey('boneyard', $view->toArray());
    }

    public function test_player_index_matches_requested_seat(): void
    {
        $match = $this->makeMatch(2);

        $view0 = (new RedactorService)->redact($match, 0);
        $view1 = (new RedactorService)->redact($match, 1);

        $this->assertSame(0, $view0->playerIndex);
        $this->assertSame(1, $view1->playerIndex);
    }

    public function test_different_players_see_different_hands(): void
    {
        $match = $this->makeMatch(2);
        $redactor = new RedactorService;

        $view0 = $redactor->redact($match, 0);
        $view1 = $redactor->redact($match, 1);

        // Tile IDs in each seat's hand should differ
        $ids0 = array_column($view0->myHand, 'id');
        $ids1 = array_column($view1->myHand, 'id');

        $this->assertEmpty(array_intersect($ids0, $ids1));
    }

    public function test_board_and_scores_are_public(): void
    {
        $match = $this->makeMatch(2);
        $view = (new RedactorService)->redact($match, 0);

        $this->assertIsArray($view->board);
        $this->assertIsArray($view->scores);
        $this->assertCount(2, $view->scores);
    }

    public function test_to_array_contains_expected_keys(): void
    {
        $match = $this->makeMatch(2);
        $view = (new RedactorService)->redact($match, 0);
        $arr = $view->toArray();

        foreach (['playerIndex', 'myHand', 'handCounts', 'board', 'boneyardCount',
            'leftEnd', 'rightEnd', 'currentPlayer', 'scores', 'matchOver',
            'roundsPlayed', 'history', 'roundResult'] as $key) {
            $this->assertArrayHasKey($key, $arr);
        }
    }
}
