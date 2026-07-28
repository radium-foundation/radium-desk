<?php

namespace App\Http\Controllers;

use App\Services\ChangelogService;
use App\Services\VersionService;
use Illuminate\View\View;

class ChangelogController extends Controller
{
    public function __invoke(ChangelogService $changelogService, VersionService $versionService): View
    {
        return view('changelog.index', [
            'entries' => $changelogService->currentReleaseEntries(),
            'missingReleaseNotesMessage' => $changelogService->missingReleaseNotesMessage(),
            'applicationLabel' => $versionService->applicationLabel(),
            'buildLabel' => $versionService->buildLabel(),
        ]);
    }
}
