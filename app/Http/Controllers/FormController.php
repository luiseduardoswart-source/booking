<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Support\FormAnswers;
use App\Support\FormInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An own form from the form builder, sent from a page on the site. The fixed
 * forms have their own pages (booking, contact).
 */
class FormController extends Controller
{
    public function store(Request $request, Form $form): RedirectResponse
    {
        abort_if(! $form->active || $form->isSystem(), 404);

        $back = fn () => redirect()->to(url()->previous().'#form-'.$form->id);

        // Honeypot: bots fill in the hidden field; they get a "thank you" and nothing is stored.
        if ($request->filled('website')) {
            return $back()->with('form_sent', $form->id);
        }

        [$rules, $names] = FormAnswers::rules($form);
        $validated = $request->validate($rules, [], $names);

        FormInbox::receive($form, FormAnswers::collect($form, (array) ($validated[FormAnswers::INPUT] ?? [])));

        return $back()->with('form_sent', $form->id);
    }
}
