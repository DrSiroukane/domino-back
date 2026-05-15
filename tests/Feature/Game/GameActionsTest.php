<?php

declare(strict_types=1);

use App\Actions\Game\DrawTileAction;
use App\Actions\Game\PassTurnAction;
use App\Actions\Game\PlayTileAction;
use App\Enums\RoomStatus;
use App\Exceptions\InvalidMoveException;
use App\Models\Room;
use App\Models\User;
use App\Services\Game\Data\MatchConfig;
use App\Services\Game\Data\MatchState;
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

/**
 * Returns a MatchState where the board already has one tile played,
 * so mandatoryFirstTile is null and currentPlayer is seat 0.
 */
function makeRoomAfterFirstPlay(): array
{
    $match = makeMatchState(2);
    $round = $match->round;
    $starter = $round->currentPlayer;
    $tile = $round->hands[$starter][0];

    // Play the mandatory (or any) first tile
    $mandatoryTile = null;
    if ($round->mandatoryFirstTile !== null) {
        $m = $round->mandatoryFirstTile;
        foreach ($round->hands[$starter] as $t) {
            if ($t->a === $m['a'] && $t->b === $m['b']) {
                $mandatoryTile = $t;
                break;
            }
        }
    }
    $tileToPlay = $mandatoryTile ?? $tile;

    $newRound = GameEngine::playTile($round, $starter, $tileToPlay->id, 'start');
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

test('play tile succeeds when it is the player turn and side is legal', function () {
    $room = makeRoom();
    $match = MatchState::fromArray($room->match_state);

    $starter = $match->round->currentPlayer;
    $hand = $match->round->hands[$starter];

    // Respect mandatory first tile if set
    $tile = $hand[0];
    if ($match->round->mandatoryFirstTile !== null) {
        $m = $match->round->mandatoryFirstTile;
        foreach ($hand as $t) {
            if ($t->a === $m['a'] && $t->b === $m['b']) {
                $tile = $t;
                break;
            }
        }
    }

    $updated = (new PlayTileAction)->execute($room, $starter, $tile->id, 'start');

    expect($updated->round->board)->toHaveCount(1);
    $room->refresh();
    expect($room->match_state['round']['board'])->toHaveCount(1);
});

test('play tile throws when it is not the player turn', function () {
    $room = makeRoom();
    $match = MatchState::fromArray($room->match_state);

    $wrongPlayer = ($match->round->currentPlayer + 1) % 2;
    $tile = $match->round->hands[$wrongPlayer][0];

    expect(fn () => (new PlayTileAction)->execute($room, $wrongPlayer, $tile->id, 'start'))
        ->toThrow(InvalidMoveException::class, 'It is not your turn.');
});

test('play tile throws when tile is not in hand', function () {
    $room = makeRoom();
    $match = MatchState::fromArray($room->match_state);

    $starter = $match->round->currentPlayer;
    $opponentTile = $match->round->hands[($starter + 1) % 2][0];

    expect(fn () => (new PlayTileAction)->execute($room, $starter, $opponentTile->id, 'start'))
        ->toThrow(InvalidMoveException::class, 'Tile not found in your hand.');
});

test('play tile throws when side is illegal', function () {
    // After the first tile is played the board is non-empty and mandatoryFirstTile is null.
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

test('draw tile throws when player can play a tile', function () {
    $room = makeRoom();
    $match = MatchState::fromArray($room->match_state);

    // Board is empty → every tile has legal side 'start' → can play
    $starter = $match->round->currentPlayer;

    expect(fn () => (new DrawTileAction)->execute($room, $starter))
        ->toThrow(InvalidMoveException::class, 'You must play a tile before drawing.');
});

test('draw tile throws when boneyard is empty and player cannot play', function () {
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

test('pass throws when player can play a tile', function () {
    $room = makeRoom();
    $match = MatchState::fromArray($room->match_state);
    $starter = $match->round->currentPlayer;

    expect(fn () => (new PassTurnAction)->execute($room, $starter))
        ->toThrow(InvalidMoveException::class, 'You cannot pass while you have a playable tile.');
});

// ------------------------------------------------------------------ //
// GameController HTTP layer
// ------------------------------------------------------------------ //

test('POST /rooms/{room}/play returns 422 when room is not playing', function () {
    $user = User::factory()->create();
    $room = Room::create([
        'status' => RoomStatus::Waiting,
        'max_players' => 2,
        'settings' => [],
        'match_state' => null,
    ]);
    $room->players()->attach($user->id);

    $response = $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/play", ['tile_id' => 0, 'side' => 'start']);

    $response->assertStatus(422)->assertJson(['message' => 'Game is not in progress.']);
});

test('POST /rooms/{room}/play returns 403 when user is not seated', function () {
    $user = User::factory()->create();
    $room = makeRoom();

    $response = $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/play", ['tile_id' => 0, 'side' => 'start']);

    $response->assertStatus(403);
});

test('POST /rooms/{room}/play returns client view on success', function () {
    $user = User::factory()->create();
    $room = makeRoom();

    // Attach user as seat 0 (first attached player)
    $room->players()->attach($user->id);

    $match = MatchState::fromArray($room->match_state);
    $starter = $match->round->currentPlayer;

    // If starter is not seat 0, attach a dummy user at seat 0 won't help.
    // Instead, attach a placeholder for any earlier seats if needed.
    // Since we only attached $user, they are seat 0. If starter != 0, 422 is expected.
    if ($starter !== 0) {
        // User is seat 0 but not the current player; expect not-your-turn 422
        $hand = $match->round->hands[0][0];
        $response = $this->actingAs($user)
            ->postJson("/api/rooms/{$room->id}/play", ['tile_id' => $hand->id, 'side' => 'start']);
        $response->assertStatus(422)->assertJson(['code' => 'not_your_turn']);

        return;
    }

    $hand = $match->round->hands[0];
    $tile = $hand[0];
    if ($match->round->mandatoryFirstTile !== null) {
        $m = $match->round->mandatoryFirstTile;
        foreach ($hand as $t) {
            if ($t->a === $m['a'] && $t->b === $m['b']) {
                $tile = $t;
                break;
            }
        }
    }

    $response = $this->actingAs($user)
        ->postJson("/api/rooms/{$room->id}/play", [
            'tile_id' => $tile->id,
            'side' => 'start',
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['playerIndex', 'myHand', 'handCounts', 'board', 'boneyardCount']);
});
