{{--
    The own fields of a form from the form builder, in a two-column grid.
    Used by the booking form, the contact form and the own forms on pages.
    Inputs are named answers[key]; see App\Support\FormAnswers.
--}}
@php
    $input = \App\Support\FormAnswers::INPUT;
    $inputClass = 'mt-1 w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500';
@endphp
@if ($form->ownFields() !== [])
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($form->ownFields() as $field)
            @php
                $key = $field['key'];
                $name = "{$input}[{$key}]";
                $old = old("{$input}.{$key}");
                $label = \App\Models\Form::text($field, 'label');
                $placeholder = \App\Models\Form::text($field, 'placeholder');
                $required = (bool) ($field['required'] ?? false);
                $span = ($field['width'] ?? 'full') === 'half' ? '' : 'sm:col-span-2';
            @endphp

            @switch($field['type'])
                @case('heading')
                    <h3 class="pt-2 text-base font-semibold text-stone-900 sm:col-span-2">{{ $label }}</h3>
                    @break

                @case('checkbox')
                    <label class="flex items-start gap-3 text-sm text-stone-700 sm:col-span-2">
                        <input type="hidden" name="{{ $name }}" value="0">
                        <input type="checkbox" name="{{ $name }}" value="1" @checked($old) @required($required)
                               class="mt-0.5 rounded border-stone-300 text-rose-600 focus:ring-rose-500">
                        <span>{{ $label }}@if ($required) *@endif</span>
                    </label>
                    @break

                @case('radio')
                @case('checkboxes')
                    <fieldset class="{{ $span }}">
                        <legend class="text-sm font-medium text-stone-700">{{ $label }}@if ($required) *@endif</legend>
                        <div class="mt-2 space-y-1">
                            @foreach (\App\Models\Form::options($field) as $i => $option)
                                <label class="flex items-center gap-2 text-sm text-stone-700">
                                    @if ($field['type'] === 'radio')
                                        <input type="radio" name="{{ $name }}" value="{{ $i }}" @checked((string) $old === (string) $i) @required($required)
                                               class="border-stone-300 text-rose-600 focus:ring-rose-500">
                                    @else
                                        <input type="checkbox" name="{{ $name }}[]" value="{{ $i }}" @checked(in_array((string) $i, array_map('strval', (array) $old), true))
                                               class="rounded border-stone-300 text-rose-600 focus:ring-rose-500">
                                    @endif
                                    <span>{{ $option }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    @break

                @default
                    <label class="block {{ $span }}">
                        <span class="text-sm font-medium text-stone-700">{{ $label }}@if ($required) *@endif</span>
                        @if ($field['type'] === 'textarea')
                            <textarea name="{{ $name }}" rows="4" placeholder="{{ $placeholder }}" @required($required)
                                      class="{{ $inputClass }}">{{ $old }}</textarea>
                        @elseif ($field['type'] === 'select')
                            <select name="{{ $name }}" @required($required) class="{{ $inputClass }}">
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach (\App\Models\Form::options($field) as $i => $option)
                                    <option value="{{ $i }}" @selected((string) $old === (string) $i)>{{ $option }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="{{ ['email' => 'email', 'phone' => 'tel', 'number' => 'number', 'date' => 'date'][$field['type']] ?? 'text' }}"
                                   name="{{ $name }}" value="{{ $old }}" placeholder="{{ $placeholder }}" @required($required)
                                   @if ($field['type'] === 'number') step="any" @endif
                                   class="{{ $inputClass }}">
                        @endif
                    </label>
            @endswitch
        @endforeach
    </div>
@endif
