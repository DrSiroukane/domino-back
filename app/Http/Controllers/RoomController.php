<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
use App\Http\Requests\CreateRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Services\BotPlayer;
use App\Services\Game\Data\MatchConfig;
use App\Services\Game\Data\MatchState;
use App\Services\Game\Enums\BlockedTiebreak;
use App\Services\Game\Enums\Difficulty;
use App\Services\Game\Enums\Opponents;
use App\Services\Game\GameEngine;
use App\Services\GameBroadcaster;
use App\Services\MatchFinalizerService;
use App\Services\TurnTimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoomController extends Controller
{
    /** Waiting rooms older than this are hidden from the lobby (creator most likely gone). */
    private const WAITING_ROOM_TTL_HOURS = 2;

    public function __construct(
        private readonly GameBroadcaster $broadcaster,
        private readonly MatchFinalizerService $finalizer,
        private readonly TurnTimerService $timer,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $rooms = Room::with('players')
            ->where('status', RoomStatus::Waiting)
            ->where('created_at', '>=', now()->subHours(self::WAITING_ROOM_TTL_HOURS))
            ->latest()
            ->get();

        return RoomResource::collection($rooms);
    }

    public function store(CreateRoomRequest $request): RoomResource
    {
        $room = Room::create([
            'max_players' => $request->validated('max_players'),
            'settings' => $request->validated('settings', []),
        ]);

        $room->seatPlayer($request->user());
        $room->load('players');

        return new RoomResource($room);
    }

    public function join(Request $request, Room $room): RoomResource|JsonResponse
    {
        $result = Room::withLock($room->id, function (Room $room) use ($request) {
            if ($room->status !== RoomStatus::Waiting) {
                return response()->json(['message' => 'Room is not open.'], 422);
            }
            if ($room->players()->where('users.id', $request->user()->id)->exists()) {
                return response()->json(['message' => 'Already in this room.'], 422);
            }
            if ($room->nextFreeSeat() === null) {
                return response()->json(['message' => 'Room is full.'], 422);
            }

            $room->seatPlayer($request->user());

            $match = null;
            if ($room->players()->count() === $room->max_players) {
                $match = $this->startGame($room);
            }

            return [$room, $match];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        [$room, $match] = $result;
        if ($match !== null) {
            $this->broadcaster->broadcastAll($room, $match);
        }

        return new RoomResource($room->load('players'));
    }

    /**
     * Leaving a waiting room frees the seat. Leaving a game in progress is a
     * forfeit: the seat stays yours (so nobody else shifts seats), a bot plays
     * it, and the match counts as a loss for you. You can come back and
     * reclaim the seat while the game is still going.
     */
    public function leave(Request $request, Room $room): JsonResponse
    {
        $result = Room::withLock($room->id, function (Room $room) use ($request) {
            $seat = $room->getPlayerIndex($request->user());
            if ($seat === null) {
                return response()->json(['message' => 'You are not in this room.'], 422);
            }

            if ($room->status === RoomStatus::Waiting) {
                $room->players()->detach($request->user()->id);
                if ($room->players()->count() === 0) {
                    $room->update(['status' => RoomStatus::Finished]);
                }

                return null;
            }

            if ($room->status !== RoomStatus::Playing) {
                return null;
            }

            $match = MatchState::fromArray($room->match_state);
            $match->botSeats = array_values(array_unique([...$match->botSeats, $seat]));
            $match->forfeitedSeats = array_values(array_unique([...$match->forfeitedSeats, $seat]));

            // Everyone has left: close the room without playing it out or touching stats.
            if (count($match->botSeats) >= $match->config->numPlayers) {
                $match->turnToken = null;
                $match->turnExpiresAt = null;
                $room->update(['status' => RoomStatus::Finished, 'match_state' => $match->toArray()]);

                return null;
            }

            $match = BotPlayer::autoPlayBots($match);
            $eloDeltas = [];
            if ($match->matchOver) {
                $eloDeltas = $this->finalizer->finalize($room, $match);
            } else {
                $match = $this->timer->arm($match);
                $room->update(['match_state' => $match->toArray()]);
                $this->timer->dispatch($room->id, $match);
            }

            return [$room, $match, $eloDeltas];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }
        if (is_array($result)) {
            [$room, $match, $eloDeltas] = $result;
            $this->broadcaster->broadcastAll($room, $match, $eloDeltas);
        }

        return response()->json(['message' => 'Left the room.']);
    }

    /** Builds and saves the opening state once the room is full (inside the room lock). */
    private function startGame(Room $room): MatchState
    {
        $s = $room->settings ?? [];
        $fourPlayers = $room->max_players === 4;

        $config = new MatchConfig(
            numPlayers: $room->max_players,
            teams: $fourPlayers && (bool) ($s['teams'] ?? true),
            opponents: Opponents::Hotseat,
            difficulty: Difficulty::Medium,
            target: (int) ($s['target'] ?? 100),
            turnTimer: (int) ($s['turnTimer'] ?? 0),
            blockedTiebreak: BlockedTiebreak::from($s['blockedTiebreak'] ?? 'sum'),
            hintCalls: (bool) ($s['hintCalls'] ?? false),
        );

        $match = $this->timer->arm(GameEngine::initMatch($config));

        $room->update([
            'status' => RoomStatus::Playing,
            'match_state' => $match->toArray(),
        ]);
        $this->timer->dispatch($room->id, $match);

        return $match;
    }
}
