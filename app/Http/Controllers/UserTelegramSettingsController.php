<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserTelegramSettingsRequest;
use App\Models\User;
use App\Services\UserTelegramSettingsService;
use Illuminate\Http\RedirectResponse;

class UserTelegramSettingsController extends Controller
{
    public function __construct(
        private readonly UserTelegramSettingsService $telegramSettingsService,
    ) {}

    public function update(UpdateUserTelegramSettingsRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $this->telegramSettingsService->updateForUser(
            target: $user,
            actor: $request->user(),
            validated: $request->validated(),
            request: $request,
        );

        return redirect()
            ->route('users.edit', $user)
            ->with('status', 'user-telegram-updated');
    }
}
