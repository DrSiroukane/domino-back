<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the WebSocket server (Reverb, Pusher-compatible API) who is connected
 * to a room's presence channel. Used to make sure a seat is really empty
 * before it can be handed to a bot.
 */
class PresenceService
{
    /**
     * User ids connected to `room.{roomId}`, or null when the WebSocket server
     * cannot be asked (not configured, or unreachable).
     *
     * @return list<int>|null
     */
    public function connectedUserIds(int $roomId): ?array
    {
        try {
            $broadcaster = Broadcast::driver();
            if (! $broadcaster instanceof PusherBroadcaster) {
                return null;
            }

            $response = $broadcaster->getPusher()->getPresenceUsers("presence-room.{$roomId}");

            return array_values(array_map(
                fn ($u) => (int) $u->id,
                (array) ($response->users ?? []),
            ));
        } catch (Throwable $e) {
            Log::warning('PresenceService: could not query presence channel', [
                'room_id' => $roomId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
