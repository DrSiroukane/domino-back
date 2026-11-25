<?php

declare(strict_types=1);

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id): bool => $user->id === $id);

// Presence channel for a table: only its players join; members are known by id and name.
Broadcast::channel('room.{roomId}', function (User $user, int $roomId): array|false {
    $room = Room::find($roomId);
    if ($room === null || $room->getPlayerIndex($user) === null) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

// Per-seat private channel for redacted game state payloads.
// Only the player occupying that seat may subscribe.
Broadcast::channel('room.{roomId}.seat.{seatIndex}', function (User $user, int $roomId, int $seatIndex): bool {
    $room = Room::find($roomId);

    return $room !== null && $room->getPlayerIndex($user) === $seatIndex;
});
