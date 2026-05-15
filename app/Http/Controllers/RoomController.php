<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
use App\Http\Requests\CreateRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
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

        return new RoomResource($room);
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
