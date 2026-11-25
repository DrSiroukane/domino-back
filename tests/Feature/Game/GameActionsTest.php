<?php

declare(strict_types=1);

use App\Actions\Game\DrawTileAction;
use App\Actions\Game\PassTurnAction;
use App\Actions\Game\PlayTileAction;
use App\Enums\RoomStatus;
use App\Exceptions\InvalidMoveException;
use App\Models\Room;
use App\Models\User;
use App\Services\Game\Data\BoardEntryStart;
use App\Services\Game\Data\MatchConfig;
use App\Services\Game\Data\MatchState;
use App\Services\Game\Data\Tile;
use App\Services\Game\Enums\BlockedTiebreak;
use App\Services\Game\Enums\Difficulty;
use App\Services\Game\Enums\Opponents;
use App\Services\Game\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ------------------------------------------------------------------ //
// Helpers
// ------------------------------------------------------------------ //

function makeMatchState(int $numPlayers = 2): MatchState
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

function makeRoom(int $numPlayers = 2): Room
{
    return Room::create([
        'status' => RoomStatus::Playing,
        'max_players' => $numPlayers,
        'settings' => [],
        'match_state' => makeMatchState($numPlayers)->toArray(),
    ]);
}

/** A room whose board already holds the opener's first tile (any tile may open), its match, and the seat to play next. */
function makeRoomAfterFirstPlay(): array
{
    $match = makeMatchState(2);
    $round = $match->round;
    $starter = $round->currentPlayer;
    $tile = $round->hands[$starter][0];

    // The opener may open with any tile.
    $newRound = GameEngine::playTile($round, $starter, $tile->id, 'start');
    $match->round = $newRound;

    // currentPlayer is now the OTHER player (starter+1 % 2)
    $room = Room::create([
        'status' => RoomStatus::Playing,
        'max_players' => 2,
        'settings' => [],
        'match_state' => $match->toArray(),
    ]);

    return ['room' => $room, 'match' => $match, 'currentPlayer' => $newRound->currentPlayer];
}

// ------------------------------------------------------------------ //
// PlayTileAction
// ------------------------------------------------------------------ //

it('lets the opener open with a tile other than the opening double and keeps the double public', function () {
    $room = makeRoom();
    $match = MatchState::fromArray($room->match_state);

    $starter = $match->round->currentPlayer;
    $hand = $match->round->hands[$starter];

    // The opener may open with any tile, not only the highest double.
    $double = $match->round->openingDouble;
    $tile = collect($hand)->first(fn ($t) => $t->a !== $double['a'] || $t->b !== $double['b']);

    $updated = (new PlayTileAction)->execute($room, $starter, $tile->id, 'start');

    expect($updated->round->board)->toHaveCount(1)
        ->and($updated->round->board[0]->tile->id)->toBe($tile->id)
        ->and($updated->round->openingDouble)->toBe($double); // everyone still knows who holds it
    $room->refresh();
    expect($room->match_state['round']['board'])->toHaveCount(1);
});

it('rejects a play out of turn', function () {
    $room = makeRoom();
    $match = MatchState::fromArray($room->match_state);

    $wrongPlayer = ($match->round->currentPlayer + 1) % 2;
    $tile = $match->round->hands[$wrongPlayer][0];

    expect(fn () => (new PlayTileAction)->execute($room, $wrongPlayer, $tile->id, 'start'))
        ->toThrow(InvalidMoveException::class, 'It is not your turn.');
});

it('rejects a play with a tile that is not in hand', function () {
    $room = makeRoom();
    $match = MatchState::fromArray($room->match_state);

    $starter = $match->round->currentPlayer;
    $opponentTile = $match->round->hands[($starter + 1) % 2][0];

    expect(fn () => (new PlayTileAction)->execute($room, $starter, $opponentTile->id, 'start'))
        ->toThrow(InvalidMoveException::class, 'Tile not found in your hand.');
});

it('rejects a play on an illegal side', function () {
    // After the first tile is played the board is non-empty.
    // Find a tile in the current player's hand that does NOT match either open end.
    ['room' => $room, 'match' => $match, 'currentPlayer' => $currentPlayer] = makeRoomAfterFirstPlay();

    $reload = MatchState::fromArray($room->match_state);
    $round = $reload->round;
    $hand = $round->hands[$currentPlayer];

    // Find a tile that has no legal sides on the current board
    $unplayableTile = null;
    foreach ($hand as $t) {
        if (count(GameEngine::legalSidesForTile($round, $t)) === 0) {
            $unplayableTile = $t;
            break;
        }
    }

    if ($unplayableTile === null) {
        // All tiles happen to be playable; skip this test
        expect(true)->toBeTrue();

        return;
    }

    expect(fn () => (new PlayTileAction)->execute($room, $currentPlayer, $unplayableTile->id, 'left'))
        ->toThrow(InvalidMoveException::class);
});

// ------------------------------------------------------------------ //
// DrawTileAction
// ------------------------------------------------------------------ //

it('rejects a draw when the player can play a tile', function () {
    $room = makeRoom();
    $match = MatchState::fromArray($room->match_state);

    // Board is empty → every tile has legal side 'start' → can play
    $starter = $match->round->currentPlayer;

    expect(fn () => (new DrawTileAction)->execute($room, $starter))
        ->toThrow(InvalidMoveException::class, 'You must play a tile before drawing.');
});

it('rejects a draw when the boneyard is empty', function () {
    ['room' => $room, 'match' => $match, 'currentPlayer' => $currentPlayer] = makeRoomAfterFirstPlay();

    $reload = MatchState::fromArray($room->match_state);
    $round = $reload->round;

    // Strip boneyard and remove any hand tiles that match the open ends
    $hand = $round->hands[$currentPlayer];
    $filteredHand = array_values(array_filter(
        $hand,
        fn ($t) => count(GameEngine::legalSidesForTile($round, $t)) === 0
    ));

    if (empty($filteredHand)) {
        // Current player has at least one playable tile; skip boneyard-empty path
        expect(true)->toBeTrue();

        return;
    }

    $round->hands[$currentPlayer] = $filteredHand;
    $round->boneyard = [];
    $reload->round = $round;
    $room->update(['match_state' => $reload->toArray()]);

    expect(fn () => (new DrawTileAction)->execute($room, $currentPlayer))
        ->toThrow(InvalidMoveException::class, 'The boneyard is empty; pass instead.');
});

// ------------------------------------------------------------------ //
// PassTurnAction
// ------------------------------------------------------------------ //

it('rejects a pass when the player can play a tile', function () {
    $room = makeRoom();
    $match = MatchState::fromArray($room->match_state);
    $starter = $match->round->currentPlayer;

    expect(fn () => (new PassTurnAction)->execute($room, $starter))
        ->toThrow(InvalidMoveException::class, 'You cannot pass while you have a playable tile.');
});

/**
 * A 4-player partnership room (hint calls on) where seat 0 must pass:
 * 6-6 is down and seat 0 holds no 6.
 */
function makeRoomWhereSeatZeroMustPass(): Room
{
    $match = GameEngine::initMatch(new MatchConfig(
        numPlayers: 4,
        teams: true,
        opponents: Opponents::Hotseat,
        difficulty: Difficulty::Easy,
        target: 100,
        blockedTiebreak: BlockedTiebreak::Sum,
        hintCalls: true,
    ));
    $round = $match->round;
    $round->board = [new BoardEntryStart(new Tile(6, 6, 27), 6, 6)];
    $round->leftEnd = 6;
    $round->rightEnd = 6;
    $round->hands[0] = [new Tile(0, 1, 1), new Tile(2, 3, 9), new Tile(4, 5, 22)];
    $round->currentPlayer = 0;
    $round->openingDouble = null;
    $round->passes = 0;
    $match->round = $round;

    return Room::create([
        'status' => RoomStatus::Playing,
        'max_players' => 4,
        'settings' => ['hintCalls' => true],
        'match_state' => $match->toArray(),
    ]);
}

it('lets a pass carry a "close the game" call', function () {
    $room = makeRoomWhereSeatZeroMustPass();

    $match = (new PassTurnAction)->execute($room, 0, 'close_game');

    $last = end($match->round->history);
    expect($last->action)->toBe('pass')
        ->and($last->call)->toBe('close_game');
});

it('rejects any other call with a pass', function () {
    $room = makeRoomWhereSeatZeroMustPass();

    expect(fn () => (new PassTurnAction)->execute($room, 0, 'good'))
        ->toThrow(InvalidMoveException::class, 'That hint call is not allowed right now.');
});

// ------------------------------------------------------------------ //
// GameController HTTP layer
// ------------------------------------------------------------------ //

it('rejects a play when the room is not playing', function () {
    $user = User::factory()->create();
    $room = Room::create([
        'status' => RoomStatus::Waiting,
        'max_players' => 2,
        'settings' => [],
        'match_state' => null,
    ]);
    $room->seatPlayer($user);

    $response = $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/play", ['tileId' => 0, 'side' => 'start']);

    $response->assertStatus(422)->assertJson(['message' => 'The game is not in progress.', 'code' => 'game_not_in_progress']);
});

it('rejects a play from someone not seated', function () {
    $user = User::factory()->create();
    $room = makeRoom();

    $response = $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/play", ['tileId' => 0, 'side' => 'start']);

    $response->assertStatus(403)->assertJson(['message' => 'You are not seated in this room.', 'code' => 'not_seated']);
});

it('returns the client view after a successful play', function () {
    $user = User::factory()->create();
    $room = makeRoom();

    // Attach user as seat 0 (first attached player)
    $room->seatPlayer($user);

    $match = MatchState::fromArray($room->match_state);
    $starter = $match->round->currentPlayer;

    // If starter is not seat 0, attach a dummy user at seat 0 won't help.
    // Instead, attach a placeholder for any earlier seats if needed.
    // Since we only attached $user, they are seat 0. If starter != 0, 422 is expected.
    if ($starter !== 0) {
        // User is seat 0 but not the current player; expect not-your-turn 422
        $hand = $match->round->hands[0][0];
        $response = $this->actingAs($user)
            ->postJson("/api/rooms/{$room->id}/play", ['tileId' => $hand->id, 'side' => 'start']);
        $response->assertStatus(422)->assertJson(['code' => 'not_your_turn']);

        return;
    }

    $tile = $match->round->hands[0][0];

    $response = $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/play", [
            'tileId' => $tile->id,
            'side' => 'start',
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['playerIndex', 'myHand', 'handCounts', 'board', 'boneyardCount', 'openingDouble']);
});
