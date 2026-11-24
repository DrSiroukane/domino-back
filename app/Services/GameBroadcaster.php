<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\GameStateUpdated;
use App\Models\Room;
use App\Services\Game\Data\ClientView;
use App\Services\Game\Data\MatchState;
use App\Services\Game\RedactorService;

/**
 * Builds per-seat redacted views and pushes them to each seat's private channel.
 * Call it after the state change has been committed.
 */
final class GameBroadcaster
{
    public function __construct(private readonly RedactorService $redactor) {}

    /** @param  array<int, array{delta: int, newElo: int}>  $eloDeltas */
    public function view(Room $room, MatchState $match, int $seat, array $eloDeltas = []): ClientView
    {
        return $this->redactor->redact($match, $seat, $eloDeltas, $room->getSeatNames(), $room->getSeatUserIds());
    }

    /** @param  array<int, array{delta: int, newElo: int}>  $eloDeltas */
    public function broadcastAll(Room $room, MatchState $match, array $eloDeltas = []): void
    {
        $names = $room->getSeatNames();
        $userIds = $room->getSeatUserIds();
        for ($seat = 0; $seat < count($match->round->hands); $seat++) {
            event(new GameStateUpdated(
                $room->id,
                $this->redactor->redact($match, $seat, $eloDeltas, $names, $userIds),
            ));
        }
    }
}
