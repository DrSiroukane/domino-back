<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('elo')->default(1000)->after('stats');
            $table->unsignedInteger('wins')->default(0)->after('elo');
            $table->unsignedInteger('losses')->default(0)->after('wins');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['elo', 'wins', 'losses']);
        });
    }
};
