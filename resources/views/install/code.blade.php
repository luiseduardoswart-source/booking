@extends('install.layout', ['step' => 0])

@section('title', __('Installation code'))
@section('subtitle', __('Just checking that you are the owner of this server.'))

@section('content')
    <p class="mb-4 text-sm text-stone-600">
        {{ __('A code has been created in a file on the server. Open that file and paste the code below:') }}
    </p>

    <p class="mb-6 rounded-lg border border-stone-200 bg-stone-50 px-4 py-3 font-mono text-sm text-stone-800">
        {{ $file }}
    </p>

    <p class="mb-6 text-xs text-stone-500">
        {{ __('You open that file through your hosting file manager, over FTP or over SSH. That way the installer knows for sure you have access to the server — a random visitor does not.') }}
    </p>

    <form method="POST" action="{{ route('install.code.verify') }}" class="space-y-4">
        @csrf

        <div>
            <label for="code" class="mb-1 block text-sm font-medium text-stone-700">{{ __('Installation code') }}</label>
            <input type="text" name="code" id="code" required autofocus autocomplete="off" spellcheck="false"
                   class="w-full rounded-lg border border-stone-300 px-3 py-2 font-mono text-sm focus:border-rose-500 focus:ring-2 focus:ring-rose-500/30 focus:outline-none">
            @error('code')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <button type="submit"
                class="w-full rounded-xl bg-gradient-to-r from-rose-600 to-pink-600 px-4 py-3 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700">
            {{ __('Continue') }}
        </button>
    </form>
@endsection
