<?php

namespace App\Http\Controllers;

use App\Services\ChangelogService;
use Illuminate\View\View;

class ChangelogController extends Controller
{
    public function __invoke(ChangelogService $changelogService): View
    {
        return view('changelog.index', [
            'entries' => $changelogService->entries(),
        ]);
    }
}
