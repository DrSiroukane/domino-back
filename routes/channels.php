<?php

use App\Models\Room;
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
