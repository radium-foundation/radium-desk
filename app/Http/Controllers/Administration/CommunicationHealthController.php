<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\CommunicationTemplate;
use App\Services\CommunicationTemplates\CommunicationTemplateHealthService;
use Illuminate\View\View;

class CommunicationHealthController extends Controller
{
    public function __invoke(CommunicationTemplateHealthService $health): View
    {
        $this->authorize('viewAny', CommunicationTemplate::class);

        return view('admin.communication-templates.health', [
            'dashboard' => $health->dashboard(),
            'canManage' => request()->user()?->can('manage', CommunicationTemplate::class) ?? false,
        ]);
    }
}
