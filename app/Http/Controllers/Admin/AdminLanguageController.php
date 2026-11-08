<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminLanguages;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The language switch in the user menu: stores the language on the account, so
 * it also applies to the emails and next time. Sits behind the panel login,
 * so only someone allowed into the admin can use it.
 */
class AdminLanguageController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, AdminLanguages::active(), true), 404);

        $request->user()->forceFill(['locale' => $locale])->save();

        return redirect()->back(fallback: Filament::getPanel('admin')->getUrl());
    }
}
