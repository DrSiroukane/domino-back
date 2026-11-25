<?php

declare(strict_types=1);

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Local-only ping on a public channel, used by the frontend's Reverb test panel.
 */
final class TestBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly string $message = 'Hello from Reverb!') {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel(Channels::TEST)];
    }

    public function broadcastAs(): string
    {
        return 'test.ping';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
