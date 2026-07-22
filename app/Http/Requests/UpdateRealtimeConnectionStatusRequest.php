<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRealtimeConnectionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['connected', 'connecting', 'disconnected', 'error', 'polling', 'offline'])],
            'provider' => ['required', 'string', Rule::in(['polling', 'ably', 'reverb', 'auto'])],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
