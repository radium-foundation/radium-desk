<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Services\Customer360Service;
use Illuminate\Http\Response;

class Customer360Controller extends Controller
{
    public function __construct(
        private readonly Customer360Service $customer360Service,
    ) {}

    public function show(Incident $incident): Response
    {
        $this->authorize('view', $incident);

        $html = view('customer-360.drawer-content', $this->customer360Service->drawerData($incident))->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
