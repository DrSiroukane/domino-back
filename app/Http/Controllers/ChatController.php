<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
use App\Events\ChatMessageSent;
use App\Exceptions\ApiException;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * In-game chat: a seated player's message, broadcast to the table.
 */
final class ChatController extends Controller
{
    public function store(Request $request, Room $room): Response
    {
        if ($room->status !== RoomStatus::Playing) {
            throw new ApiException('The game is not in progress.', 'game_not_in_progress');
        }

        $playerIndex = $room->getPlayerIndex($request->user());
        if ($playerIndex === null) {
            throw new ApiException('You are not seated in this room.', 'not_seated', 403);
        }

        // TrimStrings has already trimmed it, so a blank message fails `required`.
        $message = $request->validate(['message' => ['required', 'string', 'max:200']])['message'];

        event(new ChatMessageSent(
            roomId: $room->id,
            playerIndex: $playerIndex,
            playerName: $request->user()->name,
            message: $message,
            sentAt: now()->timestamp,
        ));

        return response()->noContent();
    }
}
