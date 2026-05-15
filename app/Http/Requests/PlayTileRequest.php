<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlayTileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tile_id' => ['required', 'integer', 'min:0', 'max:27'],
            'side' => ['required', 'string', 'in:left,right,start'],
        ];
    }
}
