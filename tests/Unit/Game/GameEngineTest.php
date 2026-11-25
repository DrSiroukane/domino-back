<?php

declare(strict_types=1);

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

function engineConfig(
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

/** A fresh first-round RoundState from the real engine (two players by default). */
function engineRound(int $numPlayers = 2, bool $teams = false): RoundState
{
    return GameEngine::initRound(engineConfig($numPlayers, $teams));
}

/** Builds a tile by (a, b). id is a+b*10 for uniqueness in tests. */
function engineTile(int $a, int $b): Tile
{
    return new Tile($a, $b, $a + $b * 10);
}

describe('makeFullSet', function () {
    it('returns 28 tiles', function () {
        $set = GameEngine::makeFullSet();
        expect($set)->toHaveCount(28);
    });

    it('numbers the tiles 0 to 27 in order', function () {
        $set = GameEngine::makeFullSet();
        $ids = array_map(fn (Tile $t) => $t->id, $set);
        expect($ids)->toBe(range(0, 27));
    });

    it('contains all unique pip pairs', function () {
        $set = GameEngine::makeFullSet();
        $pairs = array_map(fn (Tile $t) => "{$t->a}-{$t->b}", $set);
        expect(array_unique($pairs))->toHaveCount(28);
    });

    it('puts the lower pip in a on every tile', function () {
        foreach (GameEngine::makeFullSet() as $t) {
            expect($t->a)->toBeLessThanOrEqual($t->b);
        }
    });
});

describe('Tile helpers', function () {
    it('treats a tile with equal pips as a double', function () {
        expect(GameEngine::isDouble(new Tile(3, 3, 0)))->toBeTrue();
        expect(GameEngine::isDouble(new Tile(0, 0, 0)))->toBeTrue();
        expect(GameEngine::isDouble(new Tile(6, 6, 0)))->toBeTrue();
    });

    it('does not treat a tile with different pips as a double', function () {
        expect(GameEngine::isDouble(new Tile(2, 3, 0)))->toBeFalse();
        expect(GameEngine::isDouble(new Tile(0, 6, 0)))->toBeFalse();
    });

    it('counts a tile\'s pips as the sum of both halves', function () {
        expect(GameEngine::tilePips(new Tile(2, 3, 0)))->toBe(5);
        expect(GameEngine::tilePips(new Tile(6, 6, 0)))->toBe(12);
        expect(GameEngine::tilePips(new Tile(0, 0, 0)))->toBe(0);
    });

    it('finds a number on either half of a tile', function () {
        $t = new Tile(3, 5, 0);
        expect(GameEngine::tileHas($t, 3))->toBeTrue();
        expect(GameEngine::tileHas($t, 5))->toBeTrue();
        expect(GameEngine::tileHas($t, 4))->toBeFalse();
    });

    it('sums the pips of every tile in a hand', function () {
        $hand = [new Tile(1, 2, 0), new Tile(3, 4, 0), new Tile(0, 0, 0)];
        expect(GameEngine::handPips($hand))->toBe(10);
    });

    it('counts an empty hand as zero pips', function () {
        expect(GameEngine::handPips([]))->toBe(0);
    });
});

describe('handIsValid', function () {
    it('accepts a normal hand', function () {
        $hand = [
            new Tile(0, 1, 0), new Tile(1, 2, 0), new Tile(2, 3, 0),
            new Tile(3, 4, 0), new Tile(4, 5, 0), new Tile(5, 6, 0),
            new Tile(0, 6, 0),
        ];
        expect(GameEngine::handIsValid($hand))->toBeTrue();
    });

    it('rejects a hand with five or more doubles', function () {
        $hand = [
            new Tile(0, 0, 0), new Tile(1, 1, 1), new Tile(2, 2, 2),
            new Tile(3, 3, 3), new Tile(4, 4, 4),
            new Tile(0, 1, 5), new Tile(1, 2, 6),
        ];
        expect(GameEngine::handIsValid($hand))->toBeFalse();
    });

    it('rejects a hand with six or more tiles of one suit', function () {
        // Suit 1 appears 6 times (1 counts in a non-double tile)
        $hand = [
            new Tile(1, 0, 0), new Tile(1, 2, 1), new Tile(1, 3, 2),
            new Tile(1, 4, 3), new Tile(1, 5, 4), new Tile(1, 6, 5),
            new Tile(0, 0, 6),
        ];
        expect(GameEngine::handIsValid($hand))->toBeFalse();
    });

    it('accepts a hand with four doubles', function () {
        $hand = [
            new Tile(0, 0, 0), new Tile(1, 1, 1), new Tile(2, 2, 2),
            new Tile(3, 3, 3),
            new Tile(0, 1, 4), new Tile(2, 4, 5), new Tile(5, 6, 6),
        ];
        expect(GameEngine::handIsValid($hand))->toBeTrue();
    });
});

describe('shuffle', function () {
    it('returns as many tiles as it was given', function () {
        $set = GameEngine::makeFullSet();
        $shuffled = GameEngine::shuffle($set);
        expect($shuffled)->toHaveCount(28);
    });

    it('keeps the same tiles', function () {
        $set = GameEngine::makeFullSet();
        $shuffled = GameEngine::shuffle($set);
        $origIds = array_map(fn (Tile $t) => $t->id, $set);
        $shufIds = array_map(fn (Tile $t) => $t->id, $shuffled);
        sort($origIds);
        sort($shufIds);
        expect($shufIds)->toBe($origIds);
    });

    it('does not modify original', function () {
        $set = GameEngine::makeFullSet();
        $first = $set[0]->id;
        GameEngine::shuffle($set);
        expect($set[0]->id)->toBe($first);
    });
});

describe('deal', function () {
    it('deals seven tiles each and leaves fourteen in the boneyard for two players', function () {
        ['hands' => $hands, 'boneyard' => $boneyard] = GameEngine::deal(2);
        expect($hands)->toHaveCount(2);
        expect($hands[0])->toHaveCount(7);
        expect($hands[1])->toHaveCount(7);
        expect($boneyard)->toHaveCount(14);
    });

    it('deals seven tiles each and leaves no boneyard for four players', function () {
        ['hands' => $hands, 'boneyard' => $boneyard] = GameEngine::deal(4);
        expect($hands)->toHaveCount(4);
        foreach ($hands as $hand) {
            expect($hand)->toHaveCount(7);
        }
        expect($boneyard)->toHaveCount(0);
    });

    it('deals every one of the 28 tiles', function () {
        ['hands' => $hands, 'boneyard' => $boneyard] = GameEngine::deal(2);
        $all = array_merge(array_merge(...$hands), $boneyard);
        $ids = array_map(fn (Tile $t) => $t->id, $all);
        sort($ids);
        expect($ids)->toBe(range(0, 27));
    });

    it('deals no tile twice', function () {
        ['hands' => $hands, 'boneyard' => $boneyard] = GameEngine::deal(4);
        $all = array_merge(array_merge(...$hands), $boneyard);
        $ids = array_map(fn (Tile $t) => $t->id, $all);
        expect(array_unique($ids))->toHaveCount(28);
    });
});

describe('findHighestDoubleStarter', function () {
    it('picks the holder of the 6-6', function () {
        $hands = [
            [new Tile(0, 1, 0), new Tile(2, 3, 1)],
            [new Tile(6, 6, 27), new Tile(0, 0, 2)],
        ];
        $result = GameEngine::findHighestDoubleStarter($hands);
        expect($result['player'])->toBe(1);
        expect($result['tile'])->toBe(['a' => 6, 'b' => 6]);
    });

    it('prefers higher double', function () {
        $hands = [
            [new Tile(5, 5, 20)],
            [new Tile(4, 4, 14)],
        ];
        $result = GameEngine::findHighestDoubleStarter($hands);
        expect($result['player'])->toBe(0);
        expect($result['tile'])->toBe(['a' => 5, 'b' => 5]);
    });

    it('falls back to highest pips', function () {
        // No doubles — hand 1 has 6+5=11, hand 0 has 3+4=7
        $hands = [
            [new Tile(3, 4, 0)],
            [new Tile(5, 6, 1)],
        ];
        $result = GameEngine::findHighestDoubleStarter($hands);
        expect($result['player'])->toBe(1);
    });
});

describe('initRound', function () {
    it('deals a round with an empty board and no passes', function () {
        $config = engineConfig(2);
        $round = GameEngine::initRound($config);

        expect($round->hands)->toHaveCount(2);
        expect($round->hands[0])->toHaveCount(7);
        expect($round->hands[1])->toHaveCount(7);
        expect($round->boneyard)->toHaveCount(14);
        expect($round->board)->toHaveCount(0);
        expect($round->leftEnd)->toBeNull();
        expect($round->rightEnd)->toBeNull();
        expect($round->passes)->toBe(0);
        expect($round->roundOver)->toBeFalse();
        expect($round->roundResult)->toBeNull();
    });

    it('lets the last winner open, with no opening double', function () {
        $config = engineConfig(2);
        $round = GameEngine::initRound($config, 1);

        expect($round->firstMover)->toBe(1);
        expect($round->currentPlayer)->toBe(1);
        expect($round->openingDouble)->toBeNull();
    });
});

describe('initMatch', function () {
    it('starts a match with zero scores and no rounds played', function () {
        $config = engineConfig(2);
        $match = GameEngine::initMatch($config);

        expect($match->scores)->toBe([0, 0]);
        expect($match->matchOver)->toBeFalse();
        expect($match->matchWinner)->toBeNull();
        expect($match->roundsPlayed)->toBe(0);
    });

    it('keeps one score per team in a teams match', function () {
        $config = engineConfig(4, true);
        $match = GameEngine::initMatch($config);
        expect($match->scores)->toHaveCount(2);
    });

    it('keeps one score per player in an individual match', function () {
        $config = engineConfig(4, false);
        $match = GameEngine::initMatch($config);
        expect($match->scores)->toHaveCount(4);
    });
});

describe('legalSidesForTile', function () {
    it('allows only the start on an empty board', function () {
        $round = engineRound();
        $tile = engineTile(3, 5);
        expect(GameEngine::legalSidesForTile($round, $tile))->toBe(['start']);
    });

    it('allows no side for a tile that matches neither end', function () {
        $round = clone engineRound();
        $round->board = [new BoardEntryStart(engineTile(2, 4), 2, 4)];
        $round->leftEnd = 2;
        $round->rightEnd = 4;

        expect(GameEngine::legalSidesForTile($round, engineTile(0, 1)))->toBe([]);
    });

    it('allows only the left for a tile that matches the left end', function () {
        $round = clone engineRound();
        $round->board = [new BoardEntryStart(engineTile(2, 4), 2, 4)];
        $round->leftEnd = 2;
        $round->rightEnd = 4;

        expect(GameEngine::legalSidesForTile($round, engineTile(1, 2)))->toBe(['left']);
    });

    it('allows only the right for a tile that matches the right end', function () {
        $round = clone engineRound();
        $round->board = [new BoardEntryStart(engineTile(2, 4), 2, 4)];
        $round->leftEnd = 2;
        $round->rightEnd = 4;

        expect(GameEngine::legalSidesForTile($round, engineTile(4, 6)))->toBe(['right']);
    });

    it('allows both sides when the tile matches both ends', function () {
        $round = clone engineRound();
        $round->board = [new BoardEntryStart(engineTile(3, 3), 3, 3)];
        $round->leftEnd = 3;
        $round->rightEnd = 3;

        $sides = GameEngine::legalSidesForTile($round, engineTile(3, 5));
        expect($sides)->toContain('left');
        expect($sides)->toContain('right');
        expect($sides)->toHaveCount(2);
    });
});

describe('canPlayAnyTile', function () {
    it('finds a play when the hand holds a matching tile', function () {
        $round = clone engineRound();
        $round->board = [new BoardEntryStart(engineTile(3, 5), 3, 5)];
        $round->leftEnd = 3;
        $round->rightEnd = 5;

        $hand = [engineTile(0, 1), engineTile(5, 6)];
        expect(GameEngine::canPlayAnyTile($round, $hand))->toBeTrue();
    });

    it('finds no play when no tile matches either end', function () {
        $round = clone engineRound();
        $round->board = [new BoardEntryStart(engineTile(3, 5), 3, 5)];
        $round->leftEnd = 3;
        $round->rightEnd = 5;

        $hand = [engineTile(0, 1), engineTile(1, 2)];
        expect(GameEngine::canPlayAnyTile($round, $hand))->toBeFalse();
    });
});

describe('playTile', function () {
    it('places first tile on empty board', function () {
        $tile = new Tile(3, 5, 99);
        $round = clone engineRound();
        $round->hands = [[$tile], [new Tile(0, 1, 1)]];
        $round->board = [];

        $result = GameEngine::playTile($round, 0, 99, 'start');

        expect($result->board)->toHaveCount(1);
        expect($result->board[0])->toBeInstanceOf(BoardEntryStart::class);
        expect($result->leftEnd)->toBe(3);
        expect($result->rightEnd)->toBe(5);
        // Any tile may open; the opening double stays public until it is played.
        expect($result->openingDouble)->toBe($round->openingDouble);
        expect($result->passes)->toBe(0);
    });

    it('advances current player', function () {
        $tile = new Tile(2, 4, 50);
        $round = clone engineRound();
        $round->currentPlayer = 0;
        $round->hands = [[$tile], [new Tile(0, 1, 1)]];
        $round->board = [];

        $result = GameEngine::playTile($round, 0, 50, 'start');
        expect($result->currentPlayer)->toBe(1);
    });

    it('moves the left end on a play to the left', function () {
        $first = new Tile(2, 4, 1);
        $second = new Tile(1, 2, 2);

        $round = clone engineRound();
        $round->board = [new BoardEntryStart($first, 2, 4)];
        $round->leftEnd = 2;
        $round->rightEnd = 4;
        $round->hands = [[$second], []];

        $result = GameEngine::playTile($round, 0, 2, 'left');

        expect($result->leftEnd)->toBe(1); // 1-2 played left, new exposed = 1
        expect($result->rightEnd)->toBe(4); // unchanged
        expect($result->board[0])->toBeInstanceOf(BoardEntryPlayed::class);
        expect($result->board[0]->side)->toBe('left');
    });

    it('moves the right end on a play to the right', function () {
        $first = new Tile(2, 4, 1);
        $second = new Tile(4, 6, 2);

        $round = clone engineRound();
        $round->board = [new BoardEntryStart($first, 2, 4)];
        $round->leftEnd = 2;
        $round->rightEnd = 4;
        $round->hands = [[$second], []];

        $result = GameEngine::playTile($round, 0, 2, 'right');

        expect($result->leftEnd)->toBe(2);  // unchanged
        expect($result->rightEnd)->toBe(6); // 4-6 played right, new exposed = 6
    });

    it('removes tile from hand', function () {
        $tile = new Tile(2, 4, 10);
        $other = new Tile(0, 1, 11);

        $round = clone engineRound();
        $round->hands = [[$tile, $other], []];
        $round->board = [];

        $result = GameEngine::playTile($round, 0, 10, 'start');

        expect($result->hands[0])->toHaveCount(1);
        expect($result->hands[0][0]->id)->toBe(11);
    });

    it('records history entry', function () {
        $tile = new Tile(3, 3, 5);
        $round = clone engineRound();
        $round->hands = [[$tile], []];
        $round->board = [];

        $result = GameEngine::playTile($round, 0, 5, 'start');

        expect($result->history)->toHaveCount(1);
        expect($result->history[0]->action)->toBe('play');
        expect($result->history[0]->player)->toBe(0);
        expect($result->history[0]->side)->toBe('start');
    });

    it('ends the round with a domino when the last tile is played', function () {
        $tile = new Tile(3, 5, 99);
        $round = clone engineRound();
        $round->board = [new BoardEntryStart(new Tile(3, 4, 1), 3, 4)];
        $round->leftEnd = 3;
        $round->rightEnd = 4;
        $round->hands = [[$tile], [new Tile(0, 1, 2)]];

        $result = GameEngine::playTile($round, 0, 99, 'left');

        expect($result->roundOver)->toBeTrue();
        expect($result->roundResult)->toBeInstanceOf(RoundResultDomino::class);
        expect($result->roundResult->winner)->toBe(0);
    });

    it('resets passes counter', function () {
        $tile = new Tile(2, 4, 10);
        $round = clone engineRound();
        $round->passes = 1;
        $round->hands = [[$tile], []];
        $round->board = [];

        $result = GameEngine::playTile($round, 0, 10, 'start');
        expect($result->passes)->toBe(0);
    });

    it('does not mutate original state', function () {
        $tile = new Tile(2, 4, 10);
        $round = clone engineRound();
        $round->hands = [[$tile], []];
        $round->board = [];

        $boardCountBefore = count($round->board);
        $handCountBefore = count($round->hands[0]);

        GameEngine::playTile($round, 0, 10, 'start');

        expect($round->board)->toHaveCount($boardCountBefore);
        expect($round->hands[0])->toHaveCount($handCountBefore);
    });
});

describe('no capicúa bonus (rule removed)', function () {
    it('scores only the hand pips when the last tile fits both ends', function () {
        // Board ends are both 3; the last tile 3-5 fits either end. This used
        // to add a capicúa bonus — now only the opponent's pips count.
        $tile = new Tile(3, 5, 99);
        $round = clone engineRound();
        $round->board = [new BoardEntryStart(new Tile(3, 3, 1), 3, 3)];
        $round->leftEnd = 3;
        $round->rightEnd = 3;
        $round->hands = [[$tile], [new Tile(0, 1, 2)]];

        $result = GameEngine::playTile($round, 0, 99, 'left');

        expect($result->roundResult)->toBeInstanceOf(RoundResultDomino::class);
        expect($result->roundResult->points)->toBe(1);
        expect($result->roundResult->toArray())->not->toHaveKey('capicua');
    });
});

describe('passTurn', function () {
    it('increments passes and advances player', function () {
        $round = clone engineRound(2);
        $round->currentPlayer = 0;

        $result = GameEngine::passTurn($round, 0);

        expect($result->passes)->toBe(1);
        expect($result->currentPlayer)->toBe(1);
    });

    it('records history entry', function () {
        $round = clone engineRound(2);
        $result = GameEngine::passTurn($round, 0);

        expect($result->history)->toHaveCount(1);
        expect($result->history[0]->action)->toBe('pass');
        expect($result->history[0]->player)->toBe(0);
    });

    it('blocks the round when every player has passed', function () {
        $round = clone engineRound(2);
        $round->passes = 1; // Player 0 already passed

        $result = GameEngine::passTurn($round, 1); // Player 1 passes → both passed

        expect($result->roundOver)->toBeTrue();
        expect($result->roundResult)->toBeInstanceOf(RoundResultBlocked::class);
    });

    it('does not mutate original state', function () {
        $round = clone engineRound(2);
        $originalPasses = $round->passes;
        GameEngine::passTurn($round, 0);
        expect($round->passes)->toBe($originalPasses);
    });
});

describe('drawTile', function () {
    it('adds tile to hand and removes from boneyard', function () {
        $drawn = new Tile(5, 6, 20);
        $round = clone engineRound(2);
        $round->boneyard = [$drawn];
        $round->hands = [[], []];

        $result = GameEngine::drawTile($round, 0);

        expect($result->hands[0])->toHaveCount(1);
        expect($result->hands[0][0]->id)->toBe(20);
        expect($result->boneyard)->toHaveCount(0);
    });

    it('records history', function () {
        $round = clone engineRound(2);
        $round->boneyard = [new Tile(1, 2, 5)];
        $round->hands = [[], []];

        $result = GameEngine::drawTile($round, 0);

        expect($result->history)->toHaveCount(1);
        expect($result->history[0]->action)->toBe('draw');
    });

    it('returns same state when boneyard empty', function () {
        $round = clone engineRound(2);
        $round->boneyard = [];

        $result = GameEngine::drawTile($round, 0);
        expect($result)->toBe($round); // identical object reference
    });

    it('does not mutate original state', function () {
        $drawn = new Tile(5, 6, 20);
        $round = clone engineRound(2);
        $round->boneyard = [$drawn];
        $round->hands = [[], []];

        GameEngine::drawTile($round, 0);

        expect($round->boneyard)->toHaveCount(1);
        expect($round->hands[0])->toHaveCount(0);
    });
});

describe('computeRoundResult — domino, individual', function () {
    it('scores the opponents\' pips for a domino in an individual game', function () {
        $round = clone engineRound(2);
        $round->hands = [[], [new Tile(1, 2, 0), new Tile(3, 4, 1)]]; // winner=0, loser has 10 pips
        $round->numPlayers = 2;
        $round->teams = false;

        $result = GameEngine::computeRoundResult($round, 0, 'domino');

        expect($result)->toBeInstanceOf(RoundResultDomino::class);
        expect($result->winner)->toBe(0);
        expect($result->points)->toBe(10);
    });

    it('scores the opposing team\'s pips for a domino in a teams game', function () {
        // 4-player teams: players 0&2 vs 1&3. Player 0 dominoes.
        $round = clone engineRound(4, true);
        $round->hands = [
            [],                              // winner (0) — empty
            [new Tile(1, 2, 1)],             // opponent team1 (3 pips)
            [new Tile(0, 0, 2)],             // own team (not counted)
            [new Tile(4, 5, 3)],             // opponent team1 (9 pips)
        ];
        $round->numPlayers = 4;
        $round->teams = true;

        $result = GameEngine::computeRoundResult($round, 0, 'domino');

        expect($result)->toBeInstanceOf(RoundResultDomino::class);
        expect($result->winningTeam)->toBe(0);
        expect($result->points)->toBe(12); // pips of players 1 and 3
    });
});

describe('computeRoundResult — blocked, individual', function () {
    it('gives a blocked individual round to the lightest hand', function () {
        $round = clone engineRound(2);
        $round->hands = [
            [new Tile(0, 1, 0)],             // 1 pip
            [new Tile(3, 4, 1), new Tile(1, 2, 2)], // 10 pips
        ];
        $round->teams = false;
        $round->numPlayers = 2;

        $result = GameEngine::computeRoundResult($round, null, 'blocked');

        expect($result)->toBeInstanceOf(RoundResultBlocked::class);
        expect($result->winner)->toBe(0);
        expect($result->points)->toBe(10);
        expect($result->tied)->toBeFalse();
    });

    it('scores nothing for a tied blocked individual round', function () {
        $round = clone engineRound(2);
        $round->hands = [
            [new Tile(1, 2, 0)],  // 3 pips
            [new Tile(0, 3, 1)],  // 3 pips
        ];
        $round->teams = false;
        $round->numPlayers = 2;

        $result = GameEngine::computeRoundResult($round, null, 'blocked');

        expect($result->winner)->toBeNull();
        expect($result->points)->toBe(0);
        expect($result->tied)->toBeTrue();
    });
});

describe('computeRoundResult — blocked, teams', function () {
    it('gives a blocked teams round to the team with fewer pips under sum', function () {
        $round = clone engineRound(4, true);
        $round->hands = [
            [new Tile(1, 0, 0)],  // p0: 1 pip  (team0 total = 3)
            [new Tile(4, 5, 1)],  // p1: 9 pips (team1 total = 15)
            [new Tile(0, 2, 2)],  // p2: 2 pips
            [new Tile(2, 4, 3)],  // p3: 6 pips
        ];
        $round->teams = true;
        $round->numPlayers = 4;

        $result = GameEngine::computeRoundResult($round, null, 'blocked', ['blockedTiebreak' => 'sum']);

        expect($result->getWinningTeam())->toBe(0); // team0 has 3 < 15
        expect($result->points)->toBe(15);           // team1 total
        expect($result->tiebreak)->toBe('sum');
    });

    it('scores nothing when the team sums are tied', function () {
        $round = clone engineRound(4, true);
        $round->hands = [
            [new Tile(1, 2, 0)],  // p0: 3  (team0 = 5)
            [new Tile(0, 1, 1)],  // p1: 1  (team1 = 5)
            [new Tile(0, 2, 2)],  // p2: 2
            [new Tile(2, 2, 3)],  // p3: 4
        ];
        $round->teams = true;
        $round->numPlayers = 4;

        $result = GameEngine::computeRoundResult($round, null, 'blocked', ['blockedTiebreak' => 'sum']);

        expect($result->getWinningTeam())->toBeNull();
        expect($result->points)->toBe(0);
        expect($result->tied)->toBeTrue();
    });

    it('gives a blocked teams round to the lightest hand\'s team under low-player', function () {
        $round = clone engineRound(4, true);
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
        expect($result->getWinningTeam())->toBe(0);
        expect($result->lowPlayer)->toBe(0);
        expect($result->tiebreak)->toBe('low-player');
    });
});

describe('applyRoundResult', function () {
    it('updates scores', function () {
        $config = engineConfig(2, false, 100);
        $match = GameEngine::initMatch($config);

        $match->round->roundResult = new RoundResultDomino(
            winner: 0, points: 25, handTotals: [0, 25]
        );

        $updated = GameEngine::applyRoundResult($match);

        expect($updated->scores[0])->toBe(25);
        expect($updated->scores[1])->toBe(0);
        expect($updated->roundsPlayed)->toBe(1);
        expect($updated->lastWinner)->toBe(0);
    });

    it('reports the final hands', function () {
        $config = engineConfig(2, false, 100);
        $match = GameEngine::initMatch($config);
        $match->round->hands = [[], [new Tile(1, 2, 3), new Tile(0, 4, 4)]];
        $match->round->roundResult = new RoundResultDomino(winner: 0, points: 7, handTotals: [0, 7]);

        $updated = GameEngine::applyRoundResult($match);

        // Shown beside each side's score on the round-over card; public once the round is over.
        expect($updated->lastRoundResult['finalHands'])->toBe([[], [['a' => 1, 'b' => 2, 'id' => 3], ['a' => 0, 'b' => 4, 'id' => 4]]]);
        expect($updated->lastRoundResult['previousScores'])->toBe([0, 0]);
    });

    it('ends the match when a side reaches the target', function () {
        $config = engineConfig(2, false, 100);
        $match = GameEngine::initMatch($config);
        $match->scores = [95, 0];

        $match->round->roundResult = new RoundResultDomino(
            winner: 0, points: 10, handTotals: [0, 10]
        );

        $updated = GameEngine::applyRoundResult($match);

        expect($updated->matchOver)->toBeTrue();
        expect($updated->matchWinner)->toBe(0);
    });

    it('keeps the match going below the target', function () {
        $config = engineConfig(2, false, 100);
        $match = GameEngine::initMatch($config);

        $match->round->roundResult = new RoundResultDomino(
            winner: 0, points: 50, handTotals: [0, 50]
        );

        $updated = GameEngine::applyRoundResult($match);

        expect($updated->matchOver)->toBeFalse();
        expect($updated->matchWinner)->toBeNull();
    });

    it('makes the round\'s first mover the last winner on a tie', function () {
        $config = engineConfig(2, false, 100);
        $match = GameEngine::initMatch($config);
        $match->round->firstMover = 1;
        $match->round->roundResult = new RoundResultBlocked(
            points: 0, handTotals: [5, 5], winner: null, tied: true
        );

        $updated = GameEngine::applyRoundResult($match);
        expect($updated->lastWinner)->toBe(1);
    });

    it('does not mutate original', function () {
        $config = engineConfig(2, false, 100);
        $match = GameEngine::initMatch($config);
        $match->round->roundResult = new RoundResultDomino(
            winner: 0, points: 20, handTotals: [0, 20]
        );

        $originalScores = $match->scores;
        GameEngine::applyRoundResult($match);
        expect($match->scores)->toBe($originalScores);
    });
});

describe('startNextRound', function () {
    it('starts a new round opened by the last winner with no opening double', function () {
        $config = engineConfig(2);
        $match = GameEngine::initMatch($config);
        $match->lastWinner = 1;

        $updated = GameEngine::startNextRound($match);

        expect($updated->round)->not->toBe($match->round);
        expect($updated->round->firstMover)->toBe(1);
        expect($updated->round->openingDouble)->toBeNull();
    });
});

describe('Serialisation round-trip', function () {
    it('round-trips a tile through toArray and fromArray', function () {
        $tile = new Tile(3, 5, 12);
        $arr = $tile->toArray();
        $back = Tile::fromArray($arr);

        expect($back->a)->toBe($tile->a);
        expect($back->b)->toBe($tile->b);
        expect($back->id)->toBe($tile->id);
    });

    it('round-trips a match state through toArray and fromArray', function () {
        $config = engineConfig(2, false, 100);
        $match = GameEngine::initMatch($config);

        $arr = $match->toArray();
        $back = MatchState::fromArray($arr);

        expect($back->scores)->toBe($match->scores);
        expect($back->matchOver)->toBe($match->matchOver);
        expect($back->roundsPlayed)->toBe($match->roundsPlayed);
        expect($back->round->hands[0])->toHaveCount(count($match->round->hands[0]));
        expect($back->round->boneyard)->toHaveCount(count($match->round->boneyard));
    });

    it('round-trips a domino round result', function () {
        $r = new RoundResultDomino(2, 45, [0, 20, 25], winningTeam: 1);
        $arr = $r->toArray();
        $back = RoundResultDomino::fromArray($arr);

        expect($back->winner)->toBe($r->winner);
        expect($back->points)->toBe($r->points);
        expect($back->winningTeam)->toBe($r->winningTeam);
    });

    it('reads a domino round result saved with the old capicúa fields', function () {
        // Matches saved before the capicúa rule was removed still load.
        $back = RoundResultDomino::fromArray([
            'kind' => 'domino', 'winner' => 0, 'points' => 42, 'handTotals' => [0, 12],
            'capicua' => true, 'capicuaBonus' => 30, 'winningTeam' => 0,
        ]);

        expect($back->points)->toBe(42);
        expect($back->winningTeam)->toBe(0);
    });

    it('round-trips a blocked round result', function () {
        $r = new RoundResultBlocked(12, [3, 9], 0, null, null, null, null, null, null, 'sum', false);
        $arr = $r->toArray();
        $back = RoundResultBlocked::fromArray($arr);

        expect($back->winner)->toBe($r->winner);
        expect($back->points)->toBe($r->points);
        expect($back->tiebreak)->toBe($r->tiebreak);
        expect($back->tied)->toBe($r->tied);
    });

    it('reads a game saved before the opening double was renamed', function () {
        $data = engineRound()->toArray();
        $data['mandatoryFirstTile'] = ['a' => 6, 'b' => 6];
        unset($data['openingDouble']);

        expect(RoundState::fromArray($data)->openingDouble)->toBe(['a' => 6, 'b' => 6]);
    });
});
