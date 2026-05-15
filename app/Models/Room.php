<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoomStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Room extends Model
{
    protected $fillable = ['status', 'max_players', 'settings', 'match_state'];

    protected function casts(): array
    {
        return [
            'status' => RoomStatus::class,
            'settings' => 'array',
            'match_state' => 'array',
        ];
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'room_players')->withTimestamps();
    }
}
