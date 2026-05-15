<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('room.{id}', function ($user, $id) {
    $room = Room::find($id);

    if (! $room) {
        return false;
    }

    if (! $room->players()->where('user_id', $user->id)->exists()) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

// Per-seat private channel for redacted game state payloads.
// Only the player occupying that seat may subscribe.
Broadcast::channel('room.{roomId}.seat.{seatIndex}', function (User $user, int $roomId, int $seatIndex) {
    $room = Room::find($roomId);

    if (! $room) {
        return false;
    }

    return $room->getPlayerIndex($user) === $seatIndex;
});
