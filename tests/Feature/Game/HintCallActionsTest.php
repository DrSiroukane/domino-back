<?php

declare(strict_types=1);

use App\Actions\Game\PlayTileAction;
use App\Enums\RoomStatus;
use App\Exceptions\InvalidMoveException;
use App\Models\Room;
use App\Services\Game\Data\MatchConfig;
use App\Services\Game\Data\MatchState;
use App\Services\Game\Enums\BlockedTiebreak;
use App\Services\Game\Enums\Difficulty;
use App\Services\Game\Enums\Opponents;
use App\Services\Game\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function hintCallMatch(bool $hintCalls = true): MatchState
{
    return GameEngine::initMatch(new MatchConfig(
        numPlayers: 4,
        teams: true,
        opponents: Opponents::Hotseat,
        difficulty: Difficulty::Medium,
        target: 100,
        blockedTiebreak: BlockedTiebreak::Sum,
        hintCalls: $hintCalls,
    ));
}

function hintCallRoom(MatchState $match): Room
{
    return Room::create([
        'status' => RoomStatus::Playing,
        'max_players' => 4,
        'settings' => ['hintCalls' => $match->config->hintCalls],
        'match_state' => $match->toArray(),
    ]);
}

/** The seat that opens round 1 and its opening double. */
function openingMove(MatchState $match): array
{
    return [$match->round->currentPlayer, openingDoubleTile($match)];
}

it('accepts hug it on the opening double and records it in history', function () {
    $match = hintCallMatch();
    [$seat, $tile] = openingMove($match);
    $room = hintCallRoom($match);

    $after = (new PlayTileAction)->execute($room, $seat, $tile->id, 'start', 'hug_it');

    $last = end($after->round->history);
    expect($last->call)->toBe('hug_it')
        ->and($last->callNumber)->toBe($tile->a)
        ->and($room->fresh()->match_state['round']['history'][0]['call'])->toBe('hug_it');
});

it('rejects a number call on the opening move', function () {
    $match = hintCallMatch();
    [$seat, $tile] = openingMove($match);

    (new PlayTileAction)->execute(hintCallRoom($match), $seat, $tile->id, 'start', 'good');
})->throws(InvalidMoveException::class, 'That hint call is not allowed right now.');

it('rejects any call when hint calls are off', function () {
    $match = hintCallMatch(hintCalls: false);
    [$seat, $tile] = openingMove($match);

    (new PlayTileAction)->execute(hintCallRoom($match), $seat, $tile->id, 'start', 'hug_it');
})->throws(InvalidMoveException::class);

it('accepts a mid-game call without checking it against the hand (bluffs allowed)', function () {
    // Re-deal until the seat after the opener can follow the opening double.
    for ($try = 0; $try < 200; $try++) {
        $match = hintCallMatch();
        [$seat, $tile] = openingMove($match);
        $match->round = GameEngine::playTile($match->round, $seat, $tile->id, 'start');
        $next = $match->round->currentPlayer;
        foreach ($match->round->hands[$next] as $t) {
            $sides = GameEngine::legalSidesForTile($match->round, $t);
            if ($sides !== []) {
                break 2;
            }
        }
    }

    // "dead" is accepted whatever the hand really holds.
    $after = (new PlayTileAction)->execute(hintCallRoom($match), $next, $t->id, $sides[0], 'dead');

    expect(end($after->round->history)->call)->toBe('dead');
});

it('still plays without a call', function () {
    $match = hintCallMatch();
    [$seat, $tile] = openingMove($match);

    $after = (new PlayTileAction)->execute(hintCallRoom($match), $seat, $tile->id, 'start');

    expect(end($after->round->history)->call)->toBeNull();
});
