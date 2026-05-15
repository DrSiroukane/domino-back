<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Game\DrawTileAction;
use App\Actions\Game\PassTurnAction;
use App\Actions\Game\PlayTileAction;
use App\Enums\RoomStatus;
use App\Events\GameStateUpdated;
use App\Exceptions\InvalidMoveException;
use App\Http\Requests\PlayTileRequest;
use App\Jobs\TurnTimeoutJob;
use App\Models\Room;
use App\Services\Game\Data\MatchState;
use App\Services\Game\RedactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GameController extends Controller
{
    public function __construct(private readonly RedactorService $redactor) {}

    public function play(PlayTileRequest $request, Room $room): JsonResponse
    {
        return $this->handleMove($room, $request, function (int $playerIndex) use ($request, $room): MatchState {
            return (new PlayTileAction)->execute(
                $room,
                $playerIndex,
                (int) $request->validated('tile_id'),
                $request->validated('side'),
            );
        });
    }

    public function draw(Request $request, Room $room): JsonResponse
    {
        return $this->handleMove($room, $request, function (int $playerIndex) use ($room): MatchState {
            return (new DrawTileAction)->execute($room, $playerIndex);
        });
    }

    public function pass(Request $request, Room $room): JsonResponse
    {
        return $this->handleMove($room, $request, function (int $playerIndex) use ($room): MatchState {
            return (new PassTurnAction)->execute($room, $playerIndex);
        });
    }

    /** Returns the current redacted state for the authenticated player (used for reconnection). */
    public function state(Request $request, Room $room): JsonResponse
    {
        if ($room->status !== RoomStatus::Playing) {
            return response()->json(['message' => 'Game is not in progress.'], 422);
        }

        $playerIndex = $room->getPlayerIndex($request->user());
        if ($playerIndex === null) {
            return response()->json(['message' => 'You are not seated in this room.'], 403);
        }

        $match = MatchState::fromArray($room->match_state);
        $view = $this->redactor->redact($match, $playerIndex);

        return response()->json($view->toArray());
    }

    // ------------------------------------------------------------------ //

    private function handleMove(Room $room, Request $request, callable $action): JsonResponse
    {
        if ($room->status !== RoomStatus::Playing) {
            return response()->json(['message' => 'Game is not in progress.'], 422);
        }

        $playerIndex = $room->getPlayerIndex($request->user());
        if ($playerIndex === null) {
            return response()->json(['message' => 'You are not seated in this room.'], 403);
        }

        try {
            $match = $action($playerIndex);
        } catch (InvalidMoveException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }

        // Schedule authoritative turn timeout when a timer is configured
        $this->scheduleTimeout($room, $match);

        $this->broadcastAll($room, $match);

        $view = $this->redactor->redact($match, $playerIndex);

        return response()->json($view->toArray());
    }

    /** Fires a per-player GameStateUpdated event for every seat. */
    private function broadcastAll(Room $room, MatchState $match): void
    {
        for ($i = 0; $i < count($match->round->hands); $i++) {
            $view = $this->redactor->redact($match, $i);
            event(new GameStateUpdated($room->id, $view));
        }
    }

    /**
     * Generates a fresh turn token, saves it to the room, and dispatches a
     * delayed TurnTimeoutJob when a turn timer is configured and the game is live.
     */
    private function scheduleTimeout(Room $room, MatchState $match): void
    {
        $timerSeconds = $match->config->turnTimer;

        if ($timerSeconds <= 0 || $match->matchOver || $match->round->roundOver) {
            return;
        }

        $match->turnToken = Str::uuid()->toString();
        $match->turnExpiresAt = now()->timestamp + $timerSeconds;

        $room->update(['match_state' => $match->toArray()]);

        TurnTimeoutJob::dispatch($room->id, $match->round->currentPlayer, $match->turnToken)
            ->delay($timerSeconds);
    }
}
