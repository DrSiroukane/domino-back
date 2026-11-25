<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Game\Enums\HintCall;
use App\Services\Game\Enums\Side;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input for playing a tile: which tile, which side, and an optional hint call.
 */
final class PlayTileRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tileId' => ['required', 'integer', 'min:0', 'max:27'],
            'side' => ['required', 'string', Rule::enum(Side::class)],
            'call' => ['sometimes', 'nullable', 'string', Rule::enum(HintCall::class)],
        ];
    }
}
