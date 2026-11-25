<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
use App\Exceptions\ApiException;
use App\Http\Requests\CreateRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Services\Game\Data\MatchConfig;
use App\Services\Game\Data\MatchState;
use App\Services\Game\Enums\BlockedTiebreak;
use App\Services\Game\Enums\Difficulty;
use App\Services\Game\Enums\Opponents;
use App\Services\Game\GameEngine;
use App\Services\GameBroadcaster;
use App\Services\MatchSettler;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The lobby: open rooms, creating one, taking a seat and leaving.
 */
final class RoomController extends Controller
{
    /** Waiting rooms older than this are hidden from the lobby (creator most likely gone). */
    private const WAITING_ROOM_TTL_HOURS = 2;

    public function __construct(
        private readonly GameBroadcaster $broadcaster,
        private readonly MatchSettler $settler,
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
            'status' => RoomStatus::Waiting,
            'max_players' => $request->validated('maxPlayers'),
            'settings' => $request->validated('settings', []),
        ]);

        $room->seatPlayer($request->user());
        $room->load('players');

        return new RoomResource($room);
    }

    public function join(Request $request, Room $room): RoomResource
    {
        [$room, $match] = Room::withLock($room->id, function (Room $room) use ($request): array {
            if ($room->status !== RoomStatus::Waiting) {
                throw new ApiException('The room is not open.', 'room_not_open');
            }
            if ($room->players()->where('users.id', $request->user()->id)->exists()) {
                throw new ApiException('You are already in this room.', 'already_in_room');
            }
            if ($room->nextFreeSeat() === null) {
                throw new ApiException('The room is full.', 'room_full');
            }

            $room->seatPlayer($request->user());

            $match = null;
            if ($room->players()->count() === $room->max_players) {
                $match = $this->startGame($room);
            }

            return [$room, $match];
        });

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
    public function leave(Request $request, Room $room): Response
    {
        $settled = Room::withLock($room->id, function (Room $room) use ($request): ?array {
            $seat = $room->getPlayerIndex($request->user());
            if ($seat === null) {
                throw new ApiException('You are not seated in this room.', 'not_seated', 403);
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

            [$match, $eloDeltas] = $this->settler->settle($room, $match);

            return [$room, $match, $eloDeltas];
        });

        if ($settled !== null) {
            [$room, $match, $eloDeltas] = $settled;
            $this->broadcaster->broadcastAll($room, $match, $eloDeltas);
        }

        return response()->noContent();
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

        // settle() saves the room, status included, and arms the first turn timer.
        $room->status = RoomStatus::Playing;
        [$match] = $this->settler->settle($room, GameEngine::initMatch($config));

        return $match;
    }
}
