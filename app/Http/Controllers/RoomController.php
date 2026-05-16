<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
use App\Events\GameStateUpdated;
use App\Http\Requests\CreateRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Services\Game\Data\MatchConfig;
use App\Services\Game\Enums\BlockedTiebreak;
use App\Services\Game\Enums\Difficulty;
use App\Services\Game\Enums\Opponents;
use App\Services\Game\GameEngine;
use App\Services\Game\RedactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoomController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $rooms = Room::with('players')
            ->where('status', RoomStatus::Waiting)
            ->get();

        return RoomResource::collection($rooms);
    }

    public function store(CreateRoomRequest $request): RoomResource
    {
        $room = Room::create([
            'max_players' => $request->validated('max_players'),
            'settings' => $request->validated('settings', []),
        ]);

        $room->players()->attach($request->user()->id);
        $room->load('players');

        return new RoomResource($room);
    }

    public function join(Request $request, Room $room): RoomResource|JsonResponse
    {
        if ($room->status !== RoomStatus::Waiting) {
            return response()->json(['message' => 'Room is not open.'], 422);
        }

        if ($room->players()->count() >= $room->max_players) {
            return response()->json(['message' => 'Room is full.'], 422);
        }

        if ($room->players()->where('user_id', $request->user()->id)->exists()) {
            return response()->json(['message' => 'Already in this room.'], 422);
        }

        $room->players()->attach($request->user()->id);
        $room->load('players');

        if ($room->players()->count() === $room->max_players) {
            $this->startGame($room);
        }

        return new RoomResource($room);
    }

    private function startGame(Room $room): void
    {
        $s = $room->settings ?? [];

        $config = new MatchConfig(
            numPlayers: $room->max_players,
            teams: $room->max_players === 4,
            opponents: Opponents::Hotseat,
            difficulty: Difficulty::Medium,
            target: (int) ($s['target'] ?? 100),
            turnTimer: (int) ($s['turnTimer'] ?? 0),
            blockedTiebreak: BlockedTiebreak::from($s['blockedTiebreak'] ?? 'sum'),
        );

        $match = GameEngine::initMatch($config);

        $room->update([
            'status' => RoomStatus::Playing,
            'match_state' => $match->toArray(),
        ]);

        $redactor = new RedactorService;
        $seatNames = $room->getSeatNames();
        for ($i = 0; $i < $room->max_players; $i++) {
            $view = $redactor->redact($match, $i, seatNames: $seatNames);
            event(new GameStateUpdated($room->id, $view));
        }
    }

    public function leave(Request $request, Room $room): JsonResponse
    {
        $room->players()->detach($request->user()->id);

        if ($room->players()->count() === 0) {
            $room->update(['status' => RoomStatus::Finished]);
        }

        return response()->json(['message' => 'Left the room.']);
    }
}
