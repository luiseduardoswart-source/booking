<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Invitation;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The page behind the invitation link: the invitee chooses a
 * password themselves (same requirements and strength meter as at installation).
 *
 * Afterwards they simply log in via the login screen, so any
 * two-factor authentication is asked for too; this page logs nobody in itself.
 */
class AdminInvitationController extends Controller
{
    public function show(Request $request, User $user): View
    {
        if (! Invitation::isValid($request, $user)) {
            return view('admin.invitation', ['status' => 'invalid']);
        }

        return view('admin.invitation', ['status' => 'choose', 'user' => $user, 'action' => $request->fullUrl()]);
    }

    public function store(Request $request, User $user): View
    {
        if (! Invitation::isValid($request, $user)) {
            return view('admin.invitation', ['status' => 'invalid']);
        }

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill([
            'password' => $data['password'],
            'password_set_at' => now(),
        ])->save();

        return view('admin.invitation', ['status' => 'ready', 'logIn' => Filament::getPanel('admin')->getLoginUrl()]);
    }
}
