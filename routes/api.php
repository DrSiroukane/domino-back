<?php

declare(strict_types=1);

use App\Events\TestBroadcast;
use App\Exceptions\ApiException;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\RoomController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Answers 204 when the app can reach its database, 503 when it can't.
Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        throw new ApiException('The database cannot be reached.', 'database_unavailable', 503);
    }

    return response()->noContent();
});

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// Broadcasting auth via Sanctum token (the default /broadcasting/auth uses web/session guard)
Route::post('/broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
})->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn (Request $request) => new UserResource($request->user()));

    Route::get('/rooms', [RoomController::class, 'index']);
    Route::post('/rooms', [RoomController::class, 'store']);
    Route::post('/rooms/{room}/join', [RoomController::class, 'join']);
    Route::post('/rooms/{room}/leave', [RoomController::class, 'leave']);

    // In-game move actions
    Route::post('/rooms/{room}/play', [GameController::class, 'play']);
    Route::post('/rooms/{room}/draw', [GameController::class, 'draw']);
    Route::post('/rooms/{room}/pass', [GameController::class, 'pass']);
    Route::get('/rooms/{room}/state', [GameController::class, 'state']);
    Route::post('/rooms/{room}/reclaim', [GameController::class, 'reclaim']);

    // Bot substitution (any seated player may substitute a disconnected opponent)
    Route::post('/rooms/{room}/substitute-bot/{seat}', [GameController::class, 'substituteBot']);

    // In-game chat
    Route::post('/rooms/{room}/chat', [ChatController::class, 'store']);
});

// Local-only helper to trigger a Reverb broadcast for the ReverbTest component.
if (app()->isLocal()) {
    Route::post('/test-broadcast', function (Request $request) {
        event(new TestBroadcast($request->string('message', 'Hello from Reverb!')->toString()));

        return response()->noContent();
    });
}
