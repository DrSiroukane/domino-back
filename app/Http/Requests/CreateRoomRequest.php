<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateRoomRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'max_players' => ['required', 'integer', 'min:2', 'max:4'],
            'settings' => ['sometimes', 'array'],
        ];
    }
}
