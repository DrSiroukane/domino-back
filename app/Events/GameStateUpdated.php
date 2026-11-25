<?php

declare(strict_types=1);

namespace App\Events;

use App\Services\Game\Data\ClientView;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast the redacted game state to one specific player's private channel.
 *
 * Channel pattern: room.{roomId}.seat.{seatIndex}
 * Fire this once per seated player after every state-changing action.
 */
final class GameStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $roomId,
        public readonly ClientView $clientView,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $seat = $this->clientView->playerIndex;

        return [new PrivateChannel("room.{$this->roomId}.seat.{$seat}")];
    }

    public function broadcastAs(): string
    {
        return 'game.state';
    }

    public function broadcastWith(): array
    {
        return $this->clientView->toArray();
    }
}
