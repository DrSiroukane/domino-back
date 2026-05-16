<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
use App\Events\ChatMessageSent;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function store(Request $request, Room $room): JsonResponse
    {
        if ($room->status !== RoomStatus::Playing) {
            return response()->json(['message' => 'Game is not in progress.'], 422);
        }

        $playerIndex = $room->getPlayerIndex($request->user());
        if ($playerIndex === null) {
            return response()->json(['message' => 'You are not seated in this room.'], 403);
        }

        $validated = $request->validate(['message' => 'required|string|max:200']);
        $message = trim($validated['message']);

        if ($message === '') {
            return response()->json(['message' => 'Message cannot be empty.'], 422);
        }

        event(new ChatMessageSent(
            roomId: $room->id,
            playerIndex: $playerIndex,
            playerName: $request->user()->name,
            message: $message,
            sentAt: now()->timestamp,
        ));

        return response()->json(['ok' => true]);
    }
}
