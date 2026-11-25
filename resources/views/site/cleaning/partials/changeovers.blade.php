@php
    $time = fn (?string $value): ?string => filled($value) ? \Illuminate\Support\Carbon::parse($value)->format('H:i') : null;
    $people = fn (array $stay): ?string => $stay['guests'] === null ? null : trim(
        trans_choice('{1} 1 person|[2,*] :count people', $stay['guests'])
        .($stay['pets'] ? ', '.trans_choice('{1} 1 pet|[2,*] :count pets', $stay['pets']) : '')
    );
@endphp

@if ($changeovers->isEmpty())
    <p class="mt-8 rounded-xl border border-stone-200 bg-stone-50 p-6 text-stone-600">{{ __('No changeovers in this period.') }}</p>
@else
    <ul class="mt-8 space-y-4">
        @foreach ($changeovers as $changeover)
            @php
                $property = $changeover['property'];
                $out = $changeover['out'];
                $next = $changeover['next'];
            @endphp
            <li class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-lg font-semibold text-stone-900">{{ ucfirst($changeover['date']->translatedFormat('l j F')) }}</p>
                    @if ($changeover['same_day'])
                        <span class="rounded-full bg-rose-50 px-3 py-1 text-sm font-medium text-rose-700">{{ __('Same-day changeover') }}</span>
                    @endif
                </div>
                <p class="mt-1 font-medium text-stone-800">{{ $property->name }}</p>
                @if ($property->address || $property->city)
                    <p class="text-sm text-stone-500">{{ trim($property->address.', '.$property->city, ', ') }}</p>
                @endif

                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="font-medium text-stone-900">{{ __('Departure') }}</dt>
                        <dd class="text-stone-600">
                            @if ($checkOut = $time($property->effective('check_out_until')))
                                {{ __('Before :time', ['time' => $checkOut]) }}<br>
                            @endif
                            {{ collect([$people($out), $out['source']])->filter()->implode(' · ') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-stone-900">{{ __('Next arrival') }}</dt>
                        <dd class="text-stone-600">
                            @if ($next === null)
                                {{ __('None planned yet') }}
                            @else
                                {{ ucfirst($next['arrival']->translatedFormat('l j F')) }}@if ($checkIn = $time($property->effective('check_in_from'))), {{ __('from :time', ['time' => $checkIn]) }}@endif<br>
                                {{ collect([$people($next), $next['source']])->filter()->implode(' · ') }}
                            @endif
                        </dd>
                    </div>
                </dl>
            </li>
        @endforeach
    </ul>
@endif
