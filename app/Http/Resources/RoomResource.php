<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'max_players' => $this->max_players,
            'player_count' => $this->players->count(),
            'settings' => $this->settings,
            // The requesting user's seat, so the client can listen on its own seat channel only.
            'my_seat' => ($seat = $this->players->firstWhere('id', $request->user()?->id)?->pivot?->seat) === null
                ? null : (int) $seat,
            'created_at' => $this->created_at,
        ];
    }
}
