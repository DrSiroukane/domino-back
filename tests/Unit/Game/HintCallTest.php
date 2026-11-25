<?php

declare(strict_types=1);

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

function callConfig(bool $hintCalls = true, int $numPlayers = 4, bool $teams = true, Difficulty $difficulty = Difficulty::Medium): MatchConfig
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

function callTile(int $a, int $b): Tile
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
function callRound(array $hand, bool $opening = false, array $history = []): RoundState
{
    $filler = [callTile(0, 0), callTile(0, 1), callTile(0, 2)];

    return new RoundState(
        numPlayers: 4,
        teams: true,
        hands: [$hand, $filler, $filler, $filler],
        board: $opening ? [] : [new BoardEntryStart(callTile(6, 6), 6, 6)],
        leftEnd: $opening ? null : 6,
        rightEnd: $opening ? null : 6,
        boneyard: [],
        currentPlayer: 0,
        firstMover: 0,
        openingDouble: null,
        passes: 0,
        roundOver: false,
        history: $history,
        roundResult: null,
    );
}

describe('legalCalls', function () {
    it('allows no call when hint calls are off', function () {
        $round = callRound([callTile(6, 2), callTile(1, 1), callTile(3, 4)]);

        expect(GameEngine::legalCalls(callConfig(hintCalls: false), $round, 0, callTile(6, 2)))->toBe([]);
    });

    it('allows no call outside four-player teams', function () {
        $round = callRound([callTile(6, 2), callTile(1, 1), callTile(3, 4)]);

        expect(GameEngine::legalCalls(callConfig(numPlayers: 2, teams: false), $round, 0, callTile(6, 2)))->toBe([]);
    });

    it('allows only hug it when opening with a double', function () {
        $round = callRound([callTile(6, 6), callTile(1, 1), callTile(3, 4)], opening: true);

        expect(GameEngine::legalCalls(callConfig(), $round, 0, callTile(6, 6)))->toBe([HintCall::HugIt]);
    });

    it('allows no call when opening with a non-double', function () {
        $round = callRound([callTile(6, 5), callTile(1, 1), callTile(3, 4)], opening: true);

        expect(GameEngine::legalCalls(callConfig(), $round, 0, callTile(6, 5)))->toBe([]);
    });

    it('allows the number calls and close game mid-round', function () {
        $round = callRound([callTile(6, 2), callTile(1, 1), callTile(3, 4)]);

        expect(GameEngine::legalCalls(callConfig(), $round, 0, callTile(6, 2)))->toBe([HintCall::One, HintCall::Good, HintCall::Dead, HintCall::MyHead, HintCall::CloseGame]);
    });

    it('allows no call when the play leaves one tile', function () {
        $round = callRound([callTile(6, 2), callTile(1, 1)]);

        expect(GameEngine::legalCalls(callConfig(), $round, 0, callTile(6, 2)))->toBe([]);
    });
});

describe('Recording calls', function () {
    it('records a play\'s call with the open end\'s number', function () {
        $tile = callTile(6, 2);
        $round = callRound([$tile, callTile(1, 1), callTile(3, 4)]);

        $history = GameEngine::playTile($round, 0, $tile->id, 'right', HintCall::Good)->history;
        $last = end($history);

        expect($last->call)->toBe('good');
        expect($last->callNumber)->toBe(2);
        expect($last->toArray())->toBe(['player' => 0, 'action' => 'play', 'tile' => $tile->toArray(), 'side' => 'right', 'call' => 'good', 'callNumber' => 2]);
    });

    it('records close game without a number', function () {
        $tile = callTile(6, 2);
        $round = callRound([$tile, callTile(1, 1), callTile(3, 4)]);

        $history = GameEngine::playTile($round, 0, $tile->id, 'left', HintCall::CloseGame)->history;
        $last = end($history);

        expect($last->call)->toBe('close_game');
        expect($last->callNumber)->toBeNull();
    });

    it('loads and saves a history entry without a call unchanged', function () {
        $entry = HistoryEntry::fromArray(['player' => 1, 'action' => 'pass']);

        expect($entry->call)->toBeNull();
        expect($entry->toArray())->toBe(['player' => 1, 'action' => 'pass']);
    });

    it('loads an old match config with hint calls off', function () {
        $config = MatchConfig::fromArray([
            'numPlayers' => 4, 'teams' => true, 'opponents' => 'hotseat',
            'difficulty' => 'medium', 'target' => 100,
        ]);

        expect($config->hintCalls)->toBeFalse();
    });
});

describe('Bot advice', function () {
    it('says hug it when it cannot follow the double', function () {
        $double = callTile(6, 6);
        $round = callRound([$double, callTile(6, 1), callTile(3, 4)], opening: true);

        expect(HintCallAdvisor::chooseCall(callConfig(), $round, 0, $double, 'start'))->toBe(HintCall::HugIt);
    });

    it('stays silent on opening when strong in the number', function () {
        $double = callTile(6, 6);
        $round = callRound([$double, callTile(6, 1), callTile(6, 4), callTile(2, 3)], opening: true);

        expect(HintCallAdvisor::chooseCall(callConfig(), $round, 0, $double, 'start'))->toBeNull();
    });

    it('calls good dead one and my head honestly', function () {
        $tile = callTile(6, 2);
        $config = callConfig();

        $good = callRound([$tile, callTile(2, 1), callTile(2, 4), callTile(5, 5)]);
        expect(HintCallAdvisor::chooseCall($config, $good, 0, $tile, 'right', 0.99))->toBe(HintCall::Good);

        $dead = callRound([$tile, callTile(1, 3), callTile(4, 5), callTile(5, 5)]);
        expect(HintCallAdvisor::chooseCall($config, $dead, 0, $tile, 'right', 0.99))->toBe(HintCall::Dead);

        $one = callRound([$tile, callTile(2, 1), callTile(4, 5), callTile(5, 5)]);
        expect(HintCallAdvisor::chooseCall($config, $one, 0, $tile, 'right', 0.99))->toBe(HintCall::One);

        $head = callRound([$tile, callTile(2, 2), callTile(4, 5), callTile(5, 5)]);
        expect(HintCallAdvisor::chooseCall($config, $head, 0, $tile, 'right', 0.99))->toBe(HintCall::MyHead);
    });

    it('may bluff dead with one tile but easy bots never do', function () {
        $tile = callTile(6, 2);
        $round = callRound([$tile, callTile(2, 1), callTile(4, 5), callTile(5, 5), callTile(3, 4)]);

        expect(HintCallAdvisor::chooseCall(callConfig(difficulty: Difficulty::Hard), $round, 0, $tile, 'right', 0.0))->toBe(HintCall::Dead);
        expect(HintCallAdvisor::chooseCall(callConfig(difficulty: Difficulty::Easy), $round, 0, $tile, 'right', 0.0))->toBe(HintCall::One);
    });

    it('keeps partner good number open', function () {
        // Partner (seat 2) called "good" on 3 earlier this round.
        $history = [new HistoryEntry(2, 'play', callTile(5, 3), 'left', 'good', 3)];
        $round = callRound([callTile(6, 3), callTile(6, 1), callTile(4, 4)], history: $history);

        expect(HintCallAdvisor::callScore($round, 0, callTile(6, 3), 'right'))->toBeGreaterThan(HintCallAdvisor::callScore($round, 0, callTile(6, 1), 'right'));
    });

    it('answers partner hug it by playing the other side', function () {
        $history = [
            new HistoryEntry(2, 'play', callTile(6, 6), 'start', 'hug_it', 6),
            new HistoryEntry(3, 'play', callTile(6, 5), 'right'),
        ];
        $round = callRound([callTile(6, 1), callTile(4, 4), callTile(3, 3)], history: $history);

        expect(HintCallAdvisor::callScore($round, 0, callTile(6, 1), 'left'))->toBeGreaterThan(HintCallAdvisor::callScore($round, 0, callTile(6, 1), 'right'));
    });
});

describe('Passing: "close the game" call and passed-on numbers', function () {
    it('allows only close the game with a pass', function () {
        $round = callRound([callTile(1, 2), callTile(3, 4)]);

        expect(GameEngine::legalPassCalls(callConfig(), $round, 0))->toBe([HintCall::CloseGame]);
        expect(GameEngine::legalPassCalls(callConfig(hintCalls: false), $round, 0))->toBe([]);
        expect(GameEngine::legalPassCalls(callConfig(numPlayers: 2, teams: false), $round, 0))->toBe([]);
    });

    it('allows no pass call on the last tile', function () {
        $round = callRound([callTile(1, 2)]);

        expect(GameEngine::legalPassCalls(callConfig(), $round, 0))->toBe([]);
    });

    it('allows no close the game on the pass that ends the round', function () {
        $round = callRound([callTile(1, 2), callTile(3, 4)]);
        $round->passes = 3; // the other three already passed: this pass blocks the round

        expect(GameEngine::legalPassCalls(callConfig(), $round, 0))->toBe([]);
    });

    it('allows no close the game when nobody can follow either end', function () {
        // All seven 6s are on the board and both ends show 6: the game is already closed.
        $round = callRound([callTile(1, 2), callTile(3, 4)]);
        $round->board = array_map(
            fn (int $x) => new BoardEntryStart(callTile(min($x, 6), max($x, 6)), 6, 6),
            [0, 1, 2, 3, 4, 5, 6],
        );

        expect(GameEngine::endsAreDead($round))->toBeTrue();
        expect(GameEngine::legalPassCalls(callConfig(), $round, 0))->toBe([]);
    });

    it('records the call with the pass', function () {
        $round = callRound([callTile(1, 2), callTile(3, 4)]);

        $after = GameEngine::passTurn($round, 0, [], HintCall::CloseGame);
        $entry = end($after->history);

        expect($entry->action)->toBe('pass');
        expect($entry->call)->toBe('close_game');
        expect($entry->toArray())->toBe(['player' => 0, 'action' => 'pass', 'call' => 'close_game', 'callNumber' => null]);
    });

    it('collects the open ends each seat passed on', function () {
        $history = [
            new HistoryEntry(0, 'play', callTile(6, 6), 'start'),
            new HistoryEntry(1, 'pass'),                                 // ends 6 | 6
            new HistoryEntry(2, 'play', callTile(6, 2), 'right'),        // ends 6 | 2
            new HistoryEntry(3, 'play', callTile(4, 6), 'left'),         // ends 4 | 2
            new HistoryEntry(0, 'pass'),                                 // ends 4 | 2
            new HistoryEntry(1, 'pass'),                                 // ends 4 | 2
        ];
        $round = callRound([], history: $history);

        expect(GameEngine::passedNumbers($round))->toBe([1 => [2, 4, 6], 0 => [2, 4]]);
    });

    it('avoids leaving the partner stuck on a number they passed on', function () {
        // Partner (seat 2) passed on 6 | 6.
        $history = [new HistoryEntry(1, 'play', callTile(6, 6), 'start'), new HistoryEntry(2, 'pass')];
        $round = callRound([callTile(6, 1), callTile(6, 5)], history: $history);
        $round->hands[0][] = new Tile(6, 6, 99); // a second 6-6 only for the test: it would keep 6 | 6 open

        expect(HintCallAdvisor::callScore($round, 0, callTile(6, 1), 'right'))->toBeGreaterThan(HintCallAdvisor::callScore($round, 0, new Tile(6, 6, 99), 'right'));
    });

    it('prefers leaving an opponent stuck', function () {
        // Opponent (seat 1) passed on 6 | 6.
        $history = [new HistoryEntry(3, 'play', callTile(6, 6), 'start'), new HistoryEntry(1, 'pass')];
        $round = callRound([callTile(6, 1)], history: $history);

        expect(HintCallAdvisor::callScore($round, 0, new Tile(6, 6, 99), 'right'))->toBeGreaterThan(HintCallAdvisor::callScore($round, 0, callTile(6, 1), 'right'));
    });

    it('calls close the game when passing with a low hand', function () {
        $low = callRound([callTile(0, 1), callTile(0, 2), callTile(1, 1)]);    // 5 pips
        $high = callRound([callTile(5, 5), callTile(4, 3), callTile(1, 1)]);   // 19 pips

        expect(HintCallAdvisor::choosePassCall(callConfig(), $low, 0))->toBe(HintCall::CloseGame);
        expect(HintCallAdvisor::choosePassCall(callConfig(), $high, 0))->toBeNull();
        expect(HintCallAdvisor::choosePassCall(callConfig(hintCalls: false), $low, 0))->toBeNull();
    });
});
