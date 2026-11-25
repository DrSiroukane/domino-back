<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Game\Enums\HintCall;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input for playing a tile: which tile, which side, and an optional hint call.
 */
final class PlayTileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tile_id' => ['required', 'integer', 'min:0', 'max:27'],
            'side' => ['required', 'string', 'in:left,right,start'],
            'call' => ['sometimes', 'nullable', 'string', Rule::enum(HintCall::class)],
        ];
    }
}
