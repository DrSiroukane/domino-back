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
use App\Services\BotPlayer;
use App\Services\Game\Data\MatchState;
use App\Services\Game\RedactorService;
use App\Services\MatchFinalizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GameController extends Controller
{
    public function __construct(
        private readonly RedactorService $redactor,
        private readonly MatchFinalizerService $finalizer,
    ) {}

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
        $view = $this->redactor->redact($match, $playerIndex, seatNames: $room->getSeatNames());

        return response()->json($view->toArray());
    }

    /**
     * Substitutes a disconnected player with a bot at the given seat index.
     * Any seated human player may request this after an opponent has been absent.
     */
    public function substituteBot(Request $request, Room $room, int $seatIndex): JsonResponse
    {
        if ($room->status !== RoomStatus::Playing) {
            return response()->json(['message' => 'Game is not in progress.'], 422);
        }

        $requestingPlayer = $room->getPlayerIndex($request->user());
        if ($requestingPlayer === null) {
            return response()->json(['message' => 'You are not seated in this room.'], 403);
        }

        if ($requestingPlayer === $seatIndex) {
            return response()->json(['message' => 'Cannot substitute yourself.'], 422);
        }

        $match = MatchState::fromArray($room->match_state);

        if ($match->matchOver) {
            return response()->json(['message' => 'Match is already over.'], 422);
        }

        if (in_array($seatIndex, $match->botSeats, true)) {
            return response()->json(['message' => 'Seat is already a bot.'], 422);
        }

        // Mark the seat as a bot and immediately auto-play if it is that bot's turn
        $match->botSeats = array_values(array_unique([...$match->botSeats, $seatIndex]));
        $match = BotPlayer::autoPlayBots($match);

        $eloDeltas = [];
        if ($match->matchOver) {
            $eloDeltas = $this->finalizer->finalize($room, $match);
        } else {
            $room->update(['match_state' => $match->toArray()]);
        }

        $this->broadcastAll($room, $match, $eloDeltas);

        return response()->json(['ok' => true]);
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

        // Auto-play any bot-occupied seats after the human move
        if (! empty($match->botSeats) && ! $match->matchOver) {
            $match = BotPlayer::autoPlayBots($match);
            $room->update(['match_state' => $match->toArray()]);
        }

        // Finalize match stats when the game just ended
        $eloDeltas = [];
        if ($match->matchOver) {
            $eloDeltas = $this->finalizer->finalize($room, $match);
        }

        // Schedule authoritative turn timeout when a timer is configured
        $this->scheduleTimeout($room, $match);

        $this->broadcastAll($room, $match, $eloDeltas);

        $view = $this->redactor->redact($match, $playerIndex, $eloDeltas, $room->getSeatNames());

        return response()->json($view->toArray());
    }

    /** Fires a per-player GameStateUpdated event for every seat. */
    private function broadcastAll(Room $room, MatchState $match, array $eloDeltas = []): void
    {
        $seatNames = $room->getSeatNames();
        for ($i = 0; $i < count($match->round->hands); $i++) {
            $view = $this->redactor->redact($match, $i, $eloDeltas, $seatNames);
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

        // No timeout needed when it is a bot's turn
        if (in_array($match->round->currentPlayer, $match->botSeats, true)) {
            return;
        }

        $match->turnToken = Str::uuid()->toString();
        $match->turnExpiresAt = now()->timestamp + $timerSeconds;

        $room->update(['match_state' => $match->toArray()]);

        TurnTimeoutJob::dispatch($room->id, $match->round->currentPlayer, $match->turnToken)
            ->delay($timerSeconds);
    }
}
