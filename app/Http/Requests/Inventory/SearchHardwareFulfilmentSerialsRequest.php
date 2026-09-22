<?php

namespace App\Http\Requests\Inventory;

use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use App\Support\Inventory\InventorySerialNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SearchHardwareFulfilmentSerialsRequest extends FormRequest
{
    public const MAX_QUERY_LENGTH = 4096;

    public const MAX_TOKEN_COUNT = 50;

    public const MAX_TOKEN_LENGTH = 100;

    public const MAX_PARTIAL_QUERY_LENGTH = 80;

    public function authorize(): bool
    {
        return HardwareFulfilmentAccess::allows($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'commerce_order_item_id' => ['required', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:'.self::MAX_QUERY_LENGTH],
            'branch' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $query = trim((string) $this->input('q', ''));
            if ($query === '') {
                return;
            }

            $tokens = InventorySerialNumber::parseList($query);
            if ($tokens === []) {
                return;
            }

            if (count($tokens) > self::MAX_TOKEN_COUNT) {
                $validator->errors()->add(
                    'q',
                    sprintf('Search accepts at most %d serial numbers at once.', self::MAX_TOKEN_COUNT),
                );
            }

            foreach ($tokens as $token) {
                if (strlen($token) > self::MAX_TOKEN_LENGTH) {
                    $validator->errors()->add(
                        'q',
                        sprintf('Each serial number must not be greater than %d characters.', self::MAX_TOKEN_LENGTH),
                    );

                    return;
                }
            }

            if (count($tokens) === 1 && strlen($tokens[0]) > self::MAX_PARTIAL_QUERY_LENGTH) {
                $validator->errors()->add(
                    'q',
                    sprintf('The q field must not be greater than %d characters.', self::MAX_PARTIAL_QUERY_LENGTH),
                );
            }
        });
    }
}
