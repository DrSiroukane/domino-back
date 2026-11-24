<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoomStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

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
        return $this->belongsToMany(User::class, 'room_players')
            ->withPivot('seat')
            ->withTimestamps();
    }

    /**
     * Runs `$callback` inside a transaction holding a row lock on the room,
     * passing a freshly loaded copy. Every read-modify-write of `match_state`
     * and every change to the seat list must go through here, so concurrent
     * requests (double clicks, the turn-timeout job, two players taking the
     * last seat) are applied one after another instead of overwriting each other.
     *
     * @template T
     *
     * @param  callable(Room): T  $callback
     * @return T
     */
    public static function withLock(int $roomId, callable $callback): mixed
    {
        return DB::transaction(function () use ($roomId, $callback) {
            $room = self::query()->lockForUpdate()->findOrFail($roomId);

            return $callback($room);
        });
    }

    /** Lowest seat index not taken yet, or null when the room is full. */
    public function nextFreeSeat(): ?int
    {
        $taken = $this->players()->pluck('room_players.seat')->filter(fn ($s) => $s !== null)->all();
        for ($seat = 0; $seat < $this->max_players; $seat++) {
            if (! in_array($seat, $taken, true)) {
                return $seat;
            }
        }

        return null;
    }

    /** Adds `$user` to the next free seat and returns the seat index. */
    public function seatPlayer(User $user): int
    {
        $seat = $this->nextFreeSeat();
        if ($seat === null) {
            throw new \RuntimeException('Room is full.');
        }
        $this->players()->attach($user->id, ['seat' => $seat]);

        return $seat;
    }

    /** Players ordered by seat. */
    public function seatedPlayers()
    {
        return $this->players()->orderBy('room_players.seat')->get();
    }

    /** Display names for each seat, in seat order. */
    public function getSeatNames(): array
    {
        return $this->seatedPlayers()->pluck('name')->toArray();
    }

    /** User ids for each seat, in seat order (lets clients match presence by identity). */
    public function getSeatUserIds(): array
    {
        return $this->seatedPlayers()->pluck('id')->map(fn ($id) => (int) $id)->toArray();
    }

    /** The 0-based seat of `$user`, or null if the user is not a player. */
    public function getPlayerIndex(User $user): ?int
    {
        $seat = $this->players()->where('users.id', $user->id)->value('room_players.seat');

        return $seat === null ? null : (int) $seat;
    }
}
