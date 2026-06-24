<?php

namespace App\Http\Requests;

use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRemarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $remarkable = $this->resolveRemarkable();

        if (! $user || ! $remarkable) {
            return false;
        }

        if (! $user->can('remarks.create')) {
            return false;
        }

        return match (true) {
            $remarkable instanceof Order => $user->can('orders.view'),
            $remarkable instanceof Incident => $user->can('incidents.view'),
            $remarkable instanceof RefundRequest => $user->can('refunds.view'),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'remarkable_type' => ['required', 'string', Rule::in([
                Order::class,
                Incident::class,
                RefundRequest::class,
            ])],
            'remarkable_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'min:3', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => 'remark',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $this->resolveRemarkable()) {
                $validator->errors()->add('remarkable_id', 'The selected record could not be found.');
            }
        });
    }

    public function resolveRemarkable(): ?Model
    {
        if (! $this->filled('remarkable_type') || ! $this->filled('remarkable_id')) {
            return null;
        }

        $type = $this->string('remarkable_type')->toString();
        $id = $this->integer('remarkable_id');

        if (! in_array($type, [Order::class, Incident::class, RefundRequest::class], true)) {
            return null;
        }

        return $type::query()->find($id);
    }
}
