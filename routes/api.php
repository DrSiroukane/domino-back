<?php

declare(strict_types=1);

use App\Events\TestBroadcast;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RoomController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn (Request $request) => $request->user());

    Route::get('/rooms', [RoomController::class, 'index']);
    Route::post('/rooms', [RoomController::class, 'store']);
    Route::post('/rooms/{room}/join', [RoomController::class, 'join']);
    Route::post('/rooms/{room}/leave', [RoomController::class, 'leave']);
});

// Local-only helper to trigger a Reverb broadcast for the ReverbTest component.
if (app()->isLocal()) {
    Route::post('/test-broadcast', function (Request $request) {
        event(new TestBroadcast($request->string('message', 'Hello from Reverb!')->toString()));

        return response()->json(['ok' => true]);
    });
}
