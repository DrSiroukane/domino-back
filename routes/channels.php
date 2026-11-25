<?php

declare(strict_types=1);

// Who may listen on which channel. Channel names and patterns: App\Broadcasting\Channels.

use App\Broadcasting\Channels;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id): bool => $user->id === $id);

// Presence channel of a room: only its players join; members are known by id and name.
Broadcast::channel(Channels::ROOM_PATTERN, function (User $user, int $roomId): array|false {
    $room = Room::find($roomId);
    if ($room === null || $room->getPlayerIndex($user) === null) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

// Private channel of one seat: only the player in that seat may listen.
Broadcast::channel(Channels::SEAT_PATTERN, function (User $user, int $roomId, int $seat): bool {
    $room = Room::find($roomId);

    return $room !== null && $room->getPlayerIndex($user) === $seat;
});
