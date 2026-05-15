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
use App\Models\Room;
use App\Services\Game\Data\MatchState;
use App\Services\Game\RedactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $this->broadcastAll($room, $match);

        $view = $this->redactor->redact($match, $playerIndex);

        return response()->json($view->toArray());
    }

    /** Fires a per-player GameStateUpdated event for every seat. */
    private function broadcastAll(Room $room, MatchState $match): void
    {
        for ($i = 0; $i < $match->round->numPlayers; $i++) {
            $view = $this->redactor->redact($match, $i);
            event(new GameStateUpdated($room->id, $view));
        }
    }
}
