@extends('install.layout')

@section('heading', __('Admin'))
@section('title', __('Choose password'))

@section('content')
    @if ($status === 'invalid')
        <p class="text-sm text-stone-700">
            {{ __('This link has expired or has already been used. Ask the owner to send you a new invitation.') }}
        </p>
    @elseif ($status === 'ready')
        <p class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ __('Your password has been set. You can now log in.') }}
        </p>
        <a href="{{ $logIn }}"
           class="block w-full rounded-xl bg-gradient-to-r from-rose-600 to-pink-600 px-4 py-3 text-center font-semibold text-white transition hover:from-rose-700 hover:to-pink-700">
            {{ __('Go to login') }}
        </a>
    @else
        <p class="mb-6 text-sm text-stone-600">
            {{ __('Hello :name, choose your own password for :email.', ['name' => $user->name, 'email' => $user->email]) }}
        </p>

        <form method="POST" action="{{ $action }}" class="space-y-4">
            @csrf

            <x-password-fields />

            <button type="submit"
                    class="w-full rounded-xl bg-gradient-to-r from-rose-600 to-pink-600 px-4 py-3 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700">
                {{ __('Save password') }}
            </button>
        </form>
    @endif
@endsection
