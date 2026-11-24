<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Game\DrawTileAction;
use App\Actions\Game\PassTurnAction;
use App\Actions\Game\PlayTileAction;
use App\Enums\RoomStatus;
use App\Exceptions\InvalidMoveException;
use App\Http\Requests\PlayTileRequest;
use App\Models\Room;
use App\Services\BotPlayer;
use App\Services\Game\Data\MatchState;
use App\Services\GameBroadcaster;
use App\Services\MatchFinalizerService;
use App\Services\PresenceService;
use App\Services\TurnTimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function __construct(
        private readonly GameBroadcaster $broadcaster,
        private readonly MatchFinalizerService $finalizer,
        private readonly TurnTimerService $timer,
        private readonly PresenceService $presence,
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
        return $this->handleMove($room, $request, fn (Room $locked, int $seat): MatchState => (new PassTurnAction)->execute($locked, $seat));
    }

    /**
     * Current redacted state for the authenticated player (used for reconnection).
     * Also served for finished rooms so a returning player sees the final result.
     */
    public function state(Request $request, Room $room): JsonResponse
    {
        if ($room->status === RoomStatus::Waiting || $room->match_state === null) {
            return response()->json(['message' => 'Game is not in progress.'], 422);
        }

        $seat = $room->getPlayerIndex($request->user());
        if ($seat === null) {
            return response()->json(['message' => 'You are not seated in this room.'], 403);
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
        $result = Room::withLock($room->id, function (Room $room) use ($request) {
            if ($room->status !== RoomStatus::Playing) {
                return response()->json(['message' => 'Game is not in progress.'], 422);
            }

            $seat = $room->getPlayerIndex($request->user());
            if ($seat === null) {
                return response()->json(['message' => 'You are not seated in this room.'], 403);
            }

            $match = MatchState::fromArray($room->match_state);
            if (! in_array($seat, $match->botSeats, true)) {
                return [$room, $match, $seat];
            }

            $match->botSeats = array_values(array_diff($match->botSeats, [$seat]));
            $match->forfeitedSeats = array_values(array_diff($match->forfeitedSeats, [$seat]));
            $match = $this->timer->arm($match);
            $room->update(['match_state' => $match->toArray()]);
            $this->timer->dispatch($room->id, $match);

            return [$room, $match, $seat];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        [$room, $match, $seat] = $result;
        $this->broadcaster->broadcastAll($room, $match);

        return response()->json($this->broadcaster->view($room, $match, $seat)->toArray());
    }

    /**
     * Substitutes a disconnected player with a bot at the given seat.
     * Any seated player may request it, but only for a seat whose player is
     * not connected to the room's presence channel.
     */
    public function substituteBot(Request $request, Room $room, int $seatIndex): JsonResponse
    {
        $result = Room::withLock($room->id, function (Room $room) use ($request, $seatIndex) {
            if ($room->status !== RoomStatus::Playing) {
                return response()->json(['message' => 'Game is not in progress.'], 422);
            }

            $requester = $room->getPlayerIndex($request->user());
            if ($requester === null) {
                return response()->json(['message' => 'You are not seated in this room.'], 403);
            }

            $match = MatchState::fromArray($room->match_state);

            if ($seatIndex < 0 || $seatIndex >= $match->config->numPlayers) {
                return response()->json(['message' => 'No such seat.'], 422);
            }
            if ($requester === $seatIndex) {
                return response()->json(['message' => 'Cannot substitute yourself.'], 422);
            }
            if ($match->matchOver) {
                return response()->json(['message' => 'Match is already over.'], 422);
            }
            if (in_array($seatIndex, $match->botSeats, true)) {
                return response()->json(['message' => 'Seat is already a bot.'], 422);
            }

            $connected = $this->presence->connectedUserIds($room->id);
            if ($connected === null) {
                return response()->json(['message' => 'Cannot check who is connected right now. Try again.'], 503);
            }
            $targetUserId = $room->getSeatUserIds()[$seatIndex] ?? null;
            if ($targetUserId !== null && in_array($targetUserId, $connected, true)) {
                return response()->json(['message' => 'That player is still connected.'], 422);
            }

            $match->botSeats = array_values(array_unique([...$match->botSeats, $seatIndex]));

            return $this->settle($room, $match);
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        [$room, $match, $eloDeltas] = $result;
        $this->broadcaster->broadcastAll($room, $match, $eloDeltas);

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------ //

    /**
     * Runs a player's move under the room lock, lets bots answer, finalizes or
     * re-arms the turn timer, then broadcasts after the commit.
     *
     * @param  callable(Room, int): MatchState  $action
     */
    private function handleMove(Room $room, Request $request, callable $action): JsonResponse
    {
        $result = Room::withLock($room->id, function (Room $room) use ($request, $action) {
            if ($room->status !== RoomStatus::Playing) {
                return response()->json(['message' => 'Game is not in progress.'], 422);
            }

            $seat = $room->getPlayerIndex($request->user());
            if ($seat === null) {
                return response()->json(['message' => 'You are not seated in this room.'], 403);
            }

            try {
                $match = $action($room, $seat);
            } catch (InvalidMoveException $e) {
                return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
            }

            return [...$this->settle($room, $match), $seat];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        [$room, $match, $eloDeltas, $seat] = $result;
        $this->broadcaster->broadcastAll($room, $match, $eloDeltas);

        return response()->json($this->broadcaster->view($room, $match, $seat, $eloDeltas)->toArray());
    }

    /**
     * After a state change (inside the lock): bots play their turns, then the
     * match is finalized or the next turn timer is armed, and the state is saved.
     *
     * @return array{0: Room, 1: MatchState, 2: array<int, array{delta: int, newElo: int}>}
     */
    private function settle(Room $room, MatchState $match): array
    {
        $match = BotPlayer::autoPlayBots($match);

        if ($match->matchOver) {
            $match->turnToken = null;
            $match->turnExpiresAt = null;

            return [$room, $match, $this->finalizer->finalize($room, $match)];
        }

        $match = $this->timer->arm($match);
        $room->update(['match_state' => $match->toArray()]);
        $this->timer->dispatch($room->id, $match);

        return [$room, $match, []];
    }
}
