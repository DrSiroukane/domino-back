<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Game\Enums\BlockedTiebreak;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input for creating a room: player count and match settings.
 */
final class CreateRoomRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'maxPlayers' => ['required', 'integer', Rule::in([2, 4])],
            'settings' => ['sometimes', 'array'],
            'settings.target' => ['sometimes', 'integer', Rule::in([100, 150, 200])],
            'settings.turnTimer' => ['sometimes', 'integer', 'min:0', 'max:300'],
            'settings.blockedTiebreak' => ['sometimes', 'string', Rule::enum(BlockedTiebreak::class)],
            'settings.teams' => ['sometimes', 'boolean'],
            'settings.hintCalls' => ['sometimes', 'boolean'],
        ];
    }
}
