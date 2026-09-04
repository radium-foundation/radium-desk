<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\RadiumBoxReadIdentifierType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RadiumBoxReadLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $numericTypes = [
            RadiumBoxReadIdentifierType::CommercialId->value,
            RadiumBoxReadIdentifierType::RdId->value,
        ];

        return [
            'identifier_type' => ['required', 'string', Rule::enum(RadiumBoxReadIdentifierType::class)],
            'identifier' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::when(
                    in_array($this->input('identifier_type'), $numericTypes, true),
                    ['regex:/^[1-9][0-9]*$/'],
                ),
            ],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.(int) config('radiumbox_read.per_page.max', 50),
            ],
        ];
    }

    public function identifierType(): RadiumBoxReadIdentifierType
    {
        return RadiumBoxReadIdentifierType::from((string) $this->validated('identifier_type'));
    }

    public function identifier(): string
    {
        return (string) $this->validated('identifier');
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? config('radiumbox_read.per_page.default', 15));
    }
}
