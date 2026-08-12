<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileTelegramUpdateRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Services\Operations\OperationsRoleService;
use App\Services\Operations\TeamAvailabilityService;
use App\Services\UserTelegramSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(
        private readonly TeamAvailabilityService $availabilityService,
        private readonly OperationsRoleService $roleService,
        private readonly UserTelegramSettingsService $telegramSettingsService,
    ) {}

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('profile.edit', [
            'user' => $user,
            'showsTeamAvailability' => $this->roleService->isTeamMember($user),
            'availability' => $this->availabilityService->snapshotFor($user),
            'telegramSettings' => $this->telegramSettingsService->snapshotForProfile($user),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    public function updateTelegram(ProfileTelegramUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->can('users.manage')) {
            $this->telegramSettingsService->applyProfileUpdateForManager(
                $user,
                $request->validated(),
            );
        } else {
            $this->telegramSettingsService->applyProfileConnect(
                $user,
                $request->validated('telegram_chat_id'),
            );
        }

        return Redirect::route('profile.edit')->with('status', 'telegram-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
