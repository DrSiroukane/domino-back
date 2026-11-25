<?php

declare(strict_types=1);

namespace App\Broadcasting;

/**
 * Broadcast channel names and their authorization patterns, in one place.
 * domino-front builds the same names in lib/channels.ts (docs/CONTRACT.md §5).
 */
final class Channels
{
    /** Pattern of the room's presence channel, authorized in routes/channels.php. */
    public const ROOM_PATTERN = 'room.{roomId}';

    /** Pattern of a seat's private channel, authorized in routes/channels.php. */
    public const SEAT_PATTERN = 'room.{roomId}.seat.{seat}';

    /** Public channel of the local-only Reverb test panel. */
    public const TEST = 'public-test';

    /** Presence channel of a room: who is connected, and the chat. */
    public static function room(int $roomId): string
    {
        return "room.{$roomId}";
    }

    /** The room's presence channel as the WebSocket server names it (for its API). */
    public static function presenceRoom(int $roomId): string
    {
        return 'presence-'.self::room($roomId);
    }

    /** Private channel of one seat: that seat's redacted game state. */
    public static function seat(int $roomId, int $seat): string
    {
        return "room.{$roomId}.seat.{$seat}";
    }
}
