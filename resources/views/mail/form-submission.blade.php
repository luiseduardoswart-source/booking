<x-mail::message>
# {{ __('New message via :form', ['form' => $formName]) }}

@foreach ($rows as $row)
**{{ $row['label'] }}**<br>
{!! nl2br(e($row['value'])) !!}

@endforeach
<x-mail::button :url="$link">
{{ __('Open submissions') }}
</x-mail::button>

{{ $company }}
</x-mail::message>
