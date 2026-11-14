{{-- Laravel's email layout with the logo and company name from the settings
     instead of APP_NAME (which showed the Laravel logo). Only this file is
     overridden; the other mail components come from the framework. --}}
@php
    $companyName = \App\Models\Setting::get('company', 'name', config('app.name'));
    $logo = \App\Models\Setting::get('branding', 'logo');
@endphp
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
@if ($logo)
<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($logo) }}" alt="{{ $companyName }}" style="max-height: 60px; max-width: 100%; border: none;">
@else
{{ $companyName }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $companyName }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
