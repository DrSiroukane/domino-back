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

    /** Returns display names for each seat in join order. */
    public function getSeatNames(): array
    {
        return $this->players()->orderByPivot('created_at')->pluck('name')->toArray();
    }

    /**
     * Returns the 0-based seat index of $user within this room (join order),
     * or null if the user is not a player.
     */
    public function getPlayerIndex(User $user): ?int
    {
        $players = $this->players()->orderByPivot('created_at')->get();
        foreach ($players as $index => $player) {
            if ($player->id === $user->id) {
                return $index;
            }
        }

        return null;
    }
}
