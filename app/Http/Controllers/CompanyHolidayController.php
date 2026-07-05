<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyHolidayRequest;
use App\Models\CompanyHoliday;
use App\Services\Operations\CompanyHolidayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CompanyHolidayController extends Controller
{
    public function __construct(
        private readonly CompanyHolidayService $companyHolidayService,
    ) {
        $this->authorizeResource(CompanyHoliday::class, 'holiday', [
            'only' => ['index', 'store', 'destroy'],
        ]);
    }

    public function index(): View
    {
        $holidays = CompanyHoliday::query()
            ->orderByDesc('holiday_date')
            ->paginate(20);

        return view('admin.workforce.holidays.index', [
            'holidays' => $holidays,
        ]);
    }

    public function store(StoreCompanyHolidayRequest $request): RedirectResponse
    {
        $this->companyHolidayService->create($request->validated());

        return redirect()
            ->route('admin.workforce.holidays.index')
            ->with('status', 'holiday-created');
    }

    public function destroy(CompanyHoliday $holiday): RedirectResponse
    {
        $this->companyHolidayService->delete($holiday);

        return redirect()
            ->route('admin.workforce.holidays.index')
            ->with('status', 'holiday-deleted');
    }
}
