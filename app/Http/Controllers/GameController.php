<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Game\DrawTileAction;
use App\Actions\Game\PassTurnAction;
use App\Actions\Game\PlayTileAction;
use App\Enums\RoomStatus;
use App\Exceptions\ApiException;
use App\Http\Requests\PlayTileRequest;
use App\Models\Room;
use App\Services\Game\Data\MatchState;
use App\Services\Game\Enums\HintCall;
use App\Services\GameBroadcaster;
use App\Services\MatchSettler;
use App\Services\PresenceChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * In-game endpoints: moves, the redacted state, taking a seat back, and handing
 * a disconnected player's seat to a bot.
 */
final class GameController extends Controller
{
    public function __construct(
        private readonly GameBroadcaster $broadcaster,
        private readonly MatchSettler $settler,
        private readonly PresenceChecker $presence,
    ) {}

    public function play(PlayTileRequest $request, Room $room): JsonResponse
    {
        return $this->handleMove($room, $request, fn (Room $locked, int $seat): MatchState => (new PlayTileAction)->execute(
            $locked,
            $seat,
            (int) $request->validated('tile_id'),
            $request->validated('side'),
            $request->validated('call'),
        ));
    }

    public function draw(Request $request, Room $room): JsonResponse
    {
        return $this->handleMove($room, $request, fn (Room $locked, int $seat): MatchState => (new DrawTileAction)->execute($locked, $seat));
    }

    public function pass(Request $request, Room $room): JsonResponse
    {
        // Optional "close the game" call made with the pass.
        $call = $request->validate([
            'call' => ['sometimes', 'nullable', 'string', Rule::enum(HintCall::class)],
        ])['call'] ?? null;

        return $this->handleMove($room, $request, fn (Room $locked, int $seat): MatchState => (new PassTurnAction)->execute($locked, $seat, $call));
    }

    /**
     * Current redacted state for the authenticated player (used for reconnection).
     * Also served for finished rooms so a returning player sees the final result.
     */
    public function state(Request $request, Room $room): JsonResponse
    {
        if ($room->status === RoomStatus::Waiting || $room->match_state === null) {
            throw new ApiException('The game is not in progress.', 'game_not_in_progress');
        }

        $seat = $room->getPlayerIndex($request->user());
        if ($seat === null) {
            throw new ApiException('You are not seated in this room.', 'not_seated', 403);
        }

        $view = $this->broadcaster->view($room, MatchState::fromArray($room->match_state), $seat);

        return response()->json($view->toArray());
    }

    /**
     * Gives a seat back to its human player after a bot took it over
     * (disconnect substitution or leaving mid-game).
     */
    public function reclaim(Request $request, Room $room): JsonResponse
    {
        [$room, $match, $seat] = Room::withLock($room->id, function (Room $room) use ($request): array {
            if ($room->status !== RoomStatus::Playing) {
                throw new ApiException('The game is not in progress.', 'game_not_in_progress');
            }

            $seat = $room->getPlayerIndex($request->user());
            if ($seat === null) {
                throw new ApiException('You are not seated in this room.', 'not_seated', 403);
            }

            $match = MatchState::fromArray($room->match_state);
            if (! in_array($seat, $match->botSeats, true)) {
                return [$room, $match, $seat];
            }

            $match->botSeats = array_values(array_diff($match->botSeats, [$seat]));
            $match->forfeitedSeats = array_values(array_diff($match->forfeitedSeats, [$seat]));
            [$match] = $this->settler->settle($room, $match);

            return [$room, $match, $seat];
        });

        $this->broadcaster->broadcastAll($room, $match);

        return response()->json($this->broadcaster->view($room, $match, $seat)->toArray());
    }

    /**
     * Substitutes a disconnected player with a bot at the given seat.
     * Any seated player may request it, but only for a seat whose player is
     * not connected to the room's presence channel.
     */
    public function substituteBot(Request $request, Room $room, int $seatIndex): Response
    {
        [$room, $match, $eloDeltas] = Room::withLock($room->id, function (Room $room) use ($request, $seatIndex): array {
            if ($room->status !== RoomStatus::Playing) {
                throw new ApiException('The game is not in progress.', 'game_not_in_progress');
            }

            $requester = $room->getPlayerIndex($request->user());
            if ($requester === null) {
                throw new ApiException('You are not seated in this room.', 'not_seated', 403);
            }

            $match = MatchState::fromArray($room->match_state);

            if ($seatIndex < 0 || $seatIndex >= $match->config->numPlayers) {
                throw new ApiException('There is no such seat.', 'no_such_seat');
            }
            if ($requester === $seatIndex) {
                throw new ApiException('You cannot substitute yourself.', 'cannot_substitute_self');
            }
            if ($match->matchOver) {
                throw new ApiException('The match is already over.', 'match_over');
            }
            if (in_array($seatIndex, $match->botSeats, true)) {
                throw new ApiException('That seat is already played by a bot.', 'seat_already_bot');
            }

            $connected = $this->presence->connectedUserIds($room->id);
            if ($connected === null) {
                throw new ApiException('Who is connected cannot be checked right now. Try again.', 'presence_unavailable', 503);
            }
            $targetUserId = $room->getSeatUserIds()[$seatIndex] ?? null;
            if ($targetUserId !== null && in_array($targetUserId, $connected, true)) {
                throw new ApiException('That player is still connected.', 'player_connected');
            }

            $match->botSeats = array_values(array_unique([...$match->botSeats, $seatIndex]));
            [$match, $eloDeltas] = $this->settler->settle($room, $match);

            return [$room, $match, $eloDeltas];
        });

        $this->broadcaster->broadcastAll($room, $match, $eloDeltas);

        return response()->noContent();
    }

    /**
     * Runs a player's move under the room lock, settles it (bots, timer or
     * finalization), then broadcasts after the commit.
     *
     * @param  callable(Room, int): MatchState  $action
     */
    private function handleMove(Room $room, Request $request, callable $action): JsonResponse
    {
        [$room, $match, $eloDeltas, $seat] = Room::withLock($room->id, function (Room $room) use ($request, $action): array {
            if ($room->status !== RoomStatus::Playing) {
                throw new ApiException('The game is not in progress.', 'game_not_in_progress');
            }

            $seat = $room->getPlayerIndex($request->user());
            if ($seat === null) {
                throw new ApiException('You are not seated in this room.', 'not_seated', 403);
            }

            [$match, $eloDeltas] = $this->settler->settle($room, $action($room, $seat));

            return [$room, $match, $eloDeltas, $seat];
        });

        $this->broadcaster->broadcastAll($room, $match, $eloDeltas);

        return response()->json($this->broadcaster->view($room, $match, $seat, $eloDeltas)->toArray());
    }
}
