<?php

declare(strict_types=1);

use App\Events\TestBroadcast;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn (Request $request) => $request->user());
});

// Local-only helper to trigger a Reverb broadcast for the ReverbTest component.
if (app()->isLocal()) {
    Route::post('/test-broadcast', function (Request $request) {
        event(new TestBroadcast($request->string('message', 'Hello from Reverb!')->toString()));

        return response()->json(['ok' => true]);
    });
}
