<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function show(): View
    {
        return view('site.contact');
    }

    public function store(Request $request): RedirectResponse
    {
        // Honeypot: bots vullen het verborgen veld in.
        if ($request->filled('website')) {
            return back()->with('contact_sent', true);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $recipient = Setting::get('company', 'email', config('mail.from.address'));

        Mail::to($recipient)->send(new \App\Mail\ContactMessageMail(
            $validated['name'],
            $validated['email'],
            $validated['message'],
        ));

        return back()->with('contact_sent', true);
    }
}
