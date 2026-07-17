<?php

namespace App\Http\Requests;

use App\Services\Operations\LeaveRequestService;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('leave-requests.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $earliestStartDate = app(LeaveRequestService::class)
            ->earliestPermittedStartDate()
            ->toDateString();

        return [
            'start_date' => ['required', 'date', 'after_or_equal:'.$earliestStartDate],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
