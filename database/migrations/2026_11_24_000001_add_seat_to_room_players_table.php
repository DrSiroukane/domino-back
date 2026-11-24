<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores each player's seat explicitly instead of deriving it from join order.
 *
 * Deriving the seat from `created_at` broke when two players joined in the
 * same second, and every later player shifted down a seat when someone left.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_players', function (Blueprint $table) {
            $table->unsignedTinyInteger('seat')->nullable()->after('user_id');
        });

        // Backfill existing rooms using the old join-order rule.
        $roomIds = DB::table('room_players')->distinct()->pluck('room_id');
        foreach ($roomIds as $roomId) {
            $rows = DB::table('room_players')
                ->where('room_id', $roomId)
                ->orderBy('created_at')
                ->orderBy('user_id')
                ->get(['user_id']);

            foreach ($rows as $seat => $row) {
                DB::table('room_players')
                    ->where('room_id', $roomId)
                    ->where('user_id', $row->user_id)
                    ->update(['seat' => $seat]);
            }
        }

        Schema::table('room_players', function (Blueprint $table) {
            $table->unique(['room_id', 'seat']);
        });
    }

    public function down(): void
    {
        Schema::table('room_players', function (Blueprint $table) {
            $table->dropUnique(['room_id', 'seat']);
            $table->dropColumn('seat');
        });
    }
};
