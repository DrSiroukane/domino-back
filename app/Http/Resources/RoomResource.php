<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A room as the lobby and the table see it, with the caller's own seat.
 */
final class RoomResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'maxPlayers' => $this->max_players,
            'playerCount' => $this->players->count(),
            'settings' => $this->settings,
            // The requesting user's seat, so the client can listen on its own seat channel only.
            'mySeat' => ($seat = $this->players->firstWhere('id', $request->user()?->id)?->pivot?->seat) === null
                ? null : (int) $seat,
            'createdAt' => $this->created_at,
        ];
    }
}
