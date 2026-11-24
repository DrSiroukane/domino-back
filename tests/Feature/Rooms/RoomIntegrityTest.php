<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Jobs\TurnTimeoutJob;
use App\Models\Room;
use App\Models\User;
use App\Services\Game\Data\MatchConfig;
use App\Services\Game\Data\MatchState;
use App\Services\Game\Enums\BlockedTiebreak;
use App\Services\Game\Enums\Difficulty;
use App\Services\Game\Enums\Opponents;
use App\Services\Game\GameEngine;
use App\Services\GameBroadcaster;
use App\Services\MatchFinalizerService;
use App\Services\PresenceService;
use App\Services\TurnTimerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
});

/** Creates a room and seats `$users` in order through the real join endpoint. */
function roomWithPlayers(array $users, int $maxPlayers, array $settings = []): Room
{
    $room = test()->actingAs($users[0])
        ->postJson('/api/rooms', ['max_players' => $maxPlayers, 'settings' => $settings])
        ->assertCreated()
        ->json('data');

    foreach (array_slice($users, 1) as $user) {
        test()->actingAs($user)->postJson("/api/rooms/{$room['id']}/join")->assertOk();
    }

    return Room::findOrFail($room['id']);
}

/** Makes the presence check report exactly these user ids as connected (null = unavailable). */
function fakePresence(?array $connectedIds): void
{
    app()->instance(PresenceService::class, new class($connectedIds) extends PresenceService
    {
        public function __construct(private readonly ?array $ids) {}

        public function connectedUserIds(int $roomId): ?array
        {
            return $this->ids;
        }
    });
}

function finishedMatch(int $numPlayers, bool $teams, int $winner): MatchState
{
    $match = GameEngine::initMatch(new MatchConfig(
        $numPlayers, $teams, Opponents::Hotseat, Difficulty::Easy, 100, 0, BlockedTiebreak::Sum,
    ));
    $match->matchOver = true;
    $match->matchWinner = $winner;

    return $match;
}

// ------------------------------------------------------------------ //
// Seats
// ------------------------------------------------------------------ //

it('assigns seats in join order even when everyone joins in the same second', function () {
    $this->freezeTime();
    $users = User::factory()->count(4)->create()->reverse()->values()->all();

    $room = roomWithPlayers($users, 4);

    foreach ($users as $seat => $user) {
        expect($room->getPlayerIndex($user))->toBe($seat);
    }
    expect($room->getSeatUserIds())->toBe(array_map(fn ($u) => $u->id, $users));
});

it('returns the caller\'s seat with the room', function () {
    $users = User::factory()->count(2)->create()->all();
    $room = $this->actingAs($users[0])->postJson('/api/rooms', ['max_players' => 4])->json('data');

    $joined = $this->actingAs($users[1])->postJson("/api/rooms/{$room['id']}/join")->json('data');

    expect($room['my_seat'])->toBe(0)->and($joined['my_seat'])->toBe(1);
});

it('gives a seat freed in the waiting room to the next joiner without moving anyone', function () {
    [$a, $b, $c, $d] = User::factory()->count(4)->create()->all();
    $room = roomWithPlayers([$a, $b, $c], 4);

    $this->actingAs($b)->postJson("/api/rooms/{$room->id}/leave")->assertOk();
    $this->actingAs($d)->postJson("/api/rooms/{$room->id}/join")->assertOk();

    $room->refresh();
    expect($room->getPlayerIndex($a))->toBe(0)
        ->and($room->getPlayerIndex($c))->toBe(2)
        ->and($room->getPlayerIndex($d))->toBe(1);
});

it('rejects joining a full room or the same room twice', function () {
    [$a, $b, $c] = User::factory()->count(3)->create()->all();
    $room = roomWithPlayers([$a], 2);

    $this->actingAs($a)->postJson("/api/rooms/{$room->id}/join")->assertStatus(422);
    $this->actingAs($b)->postJson("/api/rooms/{$room->id}/join")->assertOk();
    $this->actingAs($c)->postJson("/api/rooms/{$room->id}/join")->assertStatus(422);

    expect($room->players()->count())->toBe(2);
});

it('keeps every seat in place when a player leaves mid-game, and a bot takes the seat', function () {
    $users = User::factory()->count(4)->create()->all();
    $room = roomWithPlayers($users, 4);

    $this->actingAs($users[1])->postJson("/api/rooms/{$room->id}/leave")->assertOk();

    $room->refresh();
    $match = MatchState::fromArray($room->match_state);
    expect($room->getPlayerIndex($users[3]))->toBe(3)
        ->and($room->getPlayerIndex($users[1]))->toBe(1)
        ->and($room->status)->toBe(RoomStatus::Playing)
        ->and($match->botSeats)->toBe([1])
        ->and($match->forfeitedSeats)->toBe([1])
        ->and($match->round->currentPlayer)->not->toBe(1);
});

it('closes the room without stats when every player has left', function () {
    $users = User::factory()->count(2)->create(['wins' => 0, 'losses' => 0])->all();
    $room = roomWithPlayers($users, 2);

    $this->actingAs($users[0])->postJson("/api/rooms/{$room->id}/leave")->assertOk();
    $this->actingAs($users[1])->postJson("/api/rooms/{$room->id}/leave")->assertOk();

    expect($room->fresh()->status)->toBe(RoomStatus::Finished)
        ->and($users[0]->fresh()->losses)->toBe(0)
        ->and($users[1]->fresh()->losses)->toBe(0);
});

it('lets a player reclaim their seat from the bot', function () {
    $users = User::factory()->count(4)->create()->all();
    $room = roomWithPlayers($users, 4);
    $this->actingAs($users[2])->postJson("/api/rooms/{$room->id}/leave")->assertOk();

    $view = $this->actingAs($users[2])->postJson("/api/rooms/{$room->id}/reclaim")->assertOk()->json();

    $match = MatchState::fromArray($room->fresh()->match_state);
    expect($view['playerIndex'])->toBe(2)
        ->and($match->botSeats)->toBe([])
        ->and($match->forfeitedSeats)->toBe([]);
});

it('serves the final state of a finished room to its players', function () {
    $users = User::factory()->count(2)->create()->all();
    $room = roomWithPlayers($users, 2);
    $room->update(['status' => RoomStatus::Finished]);

    $this->actingAs($users[1])->getJson("/api/rooms/{$room->id}/state")
        ->assertOk()->assertJsonPath('playerIndex', 1);
});

it('honours the teams setting for 4-player rooms', function () {
    $users = User::factory()->count(4)->create()->all();
    $room = roomWithPlayers($users, 4, ['teams' => false]);

    expect(MatchState::fromArray($room->match_state)->config->teams)->toBeFalse();
});

it('hides stale waiting rooms from the lobby', function () {
    $user = User::factory()->create();
    $old = roomWithPlayers([$user], 4);
    $old->forceFill(['created_at' => now()->subHours(3)])->save();
    $fresh = roomWithPlayers([$user], 4);

    $ids = collect($this->actingAs($user)->getJson('/api/rooms')->json('data'))->pluck('id');

    expect($ids)->toContain($fresh->id)->not->toContain($old->id);
});

// ------------------------------------------------------------------ //
// Bot substitution
// ------------------------------------------------------------------ //

it('refuses to replace a seat that does not exist', function () {
    $users = User::factory()->count(2)->create()->all();
    $room = roomWithPlayers($users, 2);
    fakePresence([$users[0]->id]);

    $this->actingAs($users[0])->postJson("/api/rooms/{$room->id}/substitute-bot/9")->assertStatus(422);
});

it('refuses to replace a player who is still connected', function () {
    $users = User::factory()->count(2)->create()->all();
    $room = roomWithPlayers($users, 2);
    fakePresence([$users[0]->id, $users[1]->id]);

    $this->actingAs($users[0])->postJson("/api/rooms/{$room->id}/substitute-bot/1")
        ->assertStatus(422)->assertJson(['message' => 'That player is still connected.']);
});

it('refuses when presence cannot be checked', function () {
    $users = User::factory()->count(2)->create()->all();
    $room = roomWithPlayers($users, 2);
    fakePresence(null);

    $this->actingAs($users[0])->postJson("/api/rooms/{$room->id}/substitute-bot/1")->assertStatus(503);
});

it('replaces a disconnected player with a bot', function () {
    $users = User::factory()->count(4)->create()->all();
    $room = roomWithPlayers($users, 4);
    fakePresence([$users[0]->id, $users[1]->id, $users[2]->id]);

    $this->actingAs($users[0])->postJson("/api/rooms/{$room->id}/substitute-bot/3")->assertOk();

    expect(MatchState::fromArray($room->fresh()->match_state)->botSeats)->toBe([3]);
});

// ------------------------------------------------------------------ //
// Stats
// ------------------------------------------------------------------ //

it('finalizes a match only once', function () {
    $users = User::factory()->count(2)->create(['elo' => 1000, 'wins' => 0, 'losses' => 0])->all();
    $room = roomWithPlayers($users, 2);
    $match = finishedMatch(2, false, 0);
    $finalizer = new MatchFinalizerService;

    $finalizer->finalize($room, $match);
    $finalizer->finalize($room->fresh(), $match);

    expect($users[0]->fresh()->wins)->toBe(1)
        ->and($users[0]->fresh()->elo)->toBe(1016)
        ->and($users[1]->fresh()->losses)->toBe(1);
});

it('compares a 2v2 seat with the opposing team only', function () {
    $users = User::factory()->count(4)->create(['elo' => 1000])->all();
    $users[2]->update(['elo' => 2000]); // seat 0's partner: must not change seat 0's result
    $room = roomWithPlayers($users, 4);

    $deltas = (new MatchFinalizerService)->finalize($room, finishedMatch(4, true, 0));

    expect($deltas[0]['delta'])->toBe(16);
});

it('counts a forfeited seat as a loss even when its team wins', function () {
    $users = User::factory()->count(4)->create(['elo' => 1000, 'wins' => 0, 'losses' => 0])->all();
    $room = roomWithPlayers($users, 4);
    $match = finishedMatch(4, true, 0);
    $match->botSeats = [2, 3];
    $match->forfeitedSeats = [2];

    $deltas = (new MatchFinalizerService)->finalize($room, $match);

    expect($users[2]->fresh()->losses)->toBe(1)
        ->and($users[0]->fresh()->wins)->toBe(1)
        ->and($deltas[3]['delta'])->toBe(0)
        ->and($users[3]->fresh()->losses)->toBe(0);
});

// ------------------------------------------------------------------ //
// Turn timer
// ------------------------------------------------------------------ //

it('arms the turn timer when the game starts and ignores a stale timeout', function () {
    Queue::fake();
    $users = User::factory()->count(2)->create()->all();
    $room = roomWithPlayers($users, 2, ['turnTimer' => 30]);
    $match = MatchState::fromArray($room->match_state);

    expect($match->turnToken)->not->toBeNull();
    Queue::assertPushed(TurnTimeoutJob::class, fn ($job) => $job->turnToken === $match->turnToken);

    $before = $room->fresh()->match_state;
    (new TurnTimeoutJob($room->id, $match->round->currentPlayer, 'stale-token'))->handle(
        app(GameBroadcaster::class), new MatchFinalizerService, new TurnTimerService,
    );
    expect($room->fresh()->match_state)->toBe($before);
});

it('auto-plays an expired turn and arms the next one', function () {
    Queue::fake();
    $users = User::factory()->count(2)->create()->all();
    $room = roomWithPlayers($users, 2, ['turnTimer' => 30]);
    $match = MatchState::fromArray($room->match_state);

    (new TurnTimeoutJob($room->id, $match->round->currentPlayer, $match->turnToken))->handle(
        app(GameBroadcaster::class), new MatchFinalizerService, new TurnTimerService,
    );

    $after = MatchState::fromArray($room->fresh()->match_state);
    expect(count($after->round->history))->toBeGreaterThan(0)
        ->and($after->turnToken)->not->toBe($match->turnToken);
});
