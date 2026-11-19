<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Support\FormAnswers;
use App\Support\FormInbox;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The contact page: the fixed contact form (name, email, message) plus the
 * own fields the owner added in the form builder.
 */
class ContactController extends Controller
{
    public function show(): View
    {
        return view('site.contact', ['form' => Form::fixed(Form::CONTACT)]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Honeypot: bots fill in the hidden field; they get a "thank you" and nothing is sent.
        if ($request->filled('website')) {
            return back()->with('contact_sent', true);
        }

        $form = Form::fixed(Form::CONTACT);
        [$rules, $names] = FormAnswers::rules($form);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\p{Cc}]*$/u'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            ...$rules,
        ], [], $names);

        FormInbox::receive($form, [
            FormAnswers::fixed('name', 'text', 'Name', $validated['name']),
            FormAnswers::fixed('email', 'email', 'Email address', $validated['email']),
            FormAnswers::fixed('message', 'textarea', 'Message', $validated['message']),
            ...FormAnswers::collect($form, (array) ($validated[FormAnswers::INPUT] ?? [])),
        ]);

        return back()->with('contact_sent', true);
    }
}
