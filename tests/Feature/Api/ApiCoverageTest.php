<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Events\ChatMessageSent;
use App\Models\Room;
use App\Models\User;
use App\Services\Game\Data\MatchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/** Room with `$n` seated players and the game started through the real join flow. */
function startedRoom(int $n, array $settings = []): array
{
    Event::fake();
    $users = User::factory()->count($n)->create()->all();
    $id = test()->actingAs($users[0])
        ->postJson('/api/rooms', ['max_players' => $n, 'settings' => $settings])
        ->json('data.id');
    foreach (array_slice($users, 1) as $u) {
        test()->actingAs($u)->postJson("/api/rooms/{$id}/join")->assertOk();
    }

    return [Room::findOrFail($id), $users];
}

// ------------------------------------------------------------------ //
// Auth
// ------------------------------------------------------------------ //

it('registers, logs in, returns the current user and logs out', function () {
    $register = $this->postJson('/api/register', [
        'name' => 'Nadia', 'email' => 'nadia@example.test',
        'password' => 'secret123', 'password_confirmation' => 'secret123',
    ])->assertCreated()->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);
    expect($register->json('user'))->not->toHaveKey('password');

    $token = $this->postJson('/api/login', ['email' => 'nadia@example.test', 'password' => 'secret123'])
        ->assertOk()->json('token');

    $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('email', 'nadia@example.test');
    $this->withToken($token)->postJson('/api/logout')->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
});

it('rejects a wrong password and a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.test', 'password' => 'secret123']);

    $this->postJson('/api/login', ['email' => 'taken@example.test', 'password' => 'nope-nope'])
        ->assertStatus(422)->assertJsonValidationErrors('email');
    $this->postJson('/api/register', [
        'name' => 'X', 'email' => 'taken@example.test',
        'password' => 'secret123', 'password_confirmation' => 'secret123',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('ignores rating fields sent at registration', function () {
    $this->postJson('/api/register', [
        'name' => 'Cheat', 'email' => 'cheat@example.test',
        'password' => 'secret123', 'password_confirmation' => 'secret123',
        'elo' => 9999, 'wins' => 500,
    ])->assertCreated();

    $user = User::where('email', 'cheat@example.test')->first();
    expect($user->elo)->not->toBe(9999)->and((int) $user->wins)->toBe(0);
});

it('requires a token for game routes', function () {
    $this->getJson('/api/rooms')->assertUnauthorized();
    $this->postJson('/api/rooms', ['max_players' => 2])->assertUnauthorized();
});

// ------------------------------------------------------------------ //
// Room settings validation
// ------------------------------------------------------------------ //

it('validates room settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/rooms', ['max_players' => 5])->assertStatus(422);
    $this->actingAs($user)->postJson('/api/rooms', ['max_players' => 4, 'settings' => ['blockedTiebreak' => 'last']])->assertStatus(422);
    $this->actingAs($user)->postJson('/api/rooms', ['max_players' => 4, 'settings' => ['turnTimer' => 999]])->assertStatus(422);
    $this->actingAs($user)->postJson('/api/rooms', [
        'max_players' => 4,
        'settings' => ['blockedTiebreak' => 'low-player', 'hintCalls' => true, 'teams' => true, 'target' => 150],
    ])->assertCreated();
});

// ------------------------------------------------------------------ //
// Chat
// ------------------------------------------------------------------ //

it('broadcasts a trimmed chat message from a seated player', function () {
    [$room, $users] = startedRoom(2);

    $this->actingAs($users[1])->postJson("/api/rooms/{$room->id}/chat", ['message' => '  good game  '])->assertOk();

    Event::assertDispatched(ChatMessageSent::class, fn ($e) => $e->message === 'good game' && $e->playerIndex === 1);
});

it('rejects chat from outsiders, empty messages and rooms not playing', function () {
    [$room, $users] = startedRoom(2);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->postJson("/api/rooms/{$room->id}/chat", ['message' => 'hi'])->assertForbidden();
    $this->actingAs($users[0])->postJson("/api/rooms/{$room->id}/chat", ['message' => '   '])->assertStatus(422);
    $this->actingAs($users[0])->postJson("/api/rooms/{$room->id}/chat", ['message' => str_repeat('a', 201)])->assertStatus(422);

    $room->update(['status' => RoomStatus::Finished]);
    $this->actingAs($users[0])->postJson("/api/rooms/{$room->id}/chat", ['message' => 'hi'])->assertStatus(422);
});

// ------------------------------------------------------------------ //
// WebSocket channel authorization
// ------------------------------------------------------------------ //

describe('channel authorization', function () {
    beforeEach(function () {
        // Sign channel auth locally with a Pusher-compatible driver (no network involved).
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
    });

    it('lets a player into their own seat channel only', function () {
        [$room, $users] = startedRoom(2);
        $auth = fn (User $u, string $channel) => $this->actingAs($u)->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678', 'channel_name' => $channel,
        ]);

        $auth($users[1], "private-room.{$room->id}.seat.1")->assertOk()->assertJsonStructure(['auth']);
        $auth($users[1], "private-room.{$room->id}.seat.0")->assertForbidden();
    });

    it('lets only seated players join the presence channel', function () {
        [$room, $users] = startedRoom(2);
        $outsider = User::factory()->create();
        $auth = fn (User $u) => $this->actingAs($u)->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678', 'channel_name' => "presence-room.{$room->id}",
        ]);

        $auth($users[0])->assertOk()->assertJsonStructure(['auth', 'channel_data']);
        $auth($outsider)->assertForbidden();
    });
});

// ------------------------------------------------------------------ //
// Hint calls over HTTP
// ------------------------------------------------------------------ //

it('accepts a hint call through the play endpoint and returns the updated view', function () {
    [$room, $users] = startedRoom(4, ['hintCalls' => true]);
    $match = MatchState::fromArray($room->match_state);
    $seat = $match->round->currentPlayer;
    $m = $match->round->mandatoryFirstTile;
    $tile = collect($match->round->hands[$seat])->first(fn ($t) => $t->a === $m['a'] && $t->b === $m['b']);

    $view = $this->actingAs($users[$seat])->postJson("/api/rooms/{$room->id}/play", [
        'tile_id' => $tile->id, 'side' => 'start', 'call' => 'hug_it',
    ])->assertOk()->json();

    expect($view['history'][0]['call'])->toBe('hug_it')
        ->and($view['playerIndex'])->toBe($seat)
        ->and($view)->toHaveKey('seatUserIds');
});

it('rejects an unknown hint call value', function () {
    [$room, $users] = startedRoom(4, ['hintCalls' => true]);
    $seat = MatchState::fromArray($room->match_state)->round->currentPlayer;

    $this->actingAs($users[$seat])->postJson("/api/rooms/{$room->id}/play", [
        'tile_id' => 0, 'side' => 'start', 'call' => 'shout',
    ])->assertStatus(422)->assertJsonValidationErrors('call');
});

it('never exposes other players\' tiles in the state endpoint', function () {
    [$room, $users] = startedRoom(4);

    $view = $this->actingAs($users[2])->getJson("/api/rooms/{$room->id}/state")->assertOk()->json();

    expect($view)->not->toHaveKey('hands')->not->toHaveKey('boneyard')
        ->and($view['myHand'])->toHaveCount(7)
        ->and($view['handCounts'])->toBe([7, 7, 7, 7]);
});
