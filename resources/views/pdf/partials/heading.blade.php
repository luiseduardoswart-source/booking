<div class="heading">
    <div class="company">
        <strong>{{ $company['name'] }}</strong><br>
        @if ($company['address']){{ $company['address'] }}<br>@endif
        @if ($company['postal_code_city']){{ $company['postal_code_city'] }}<br>@endif
        @if ($company['email']){{ $company['email'] }}<br>@endif
        @if ($company['phone']){{ $company['phone'] }}<br>@endif
        @if ($company['coc'])KvK: {{ $company['coc'] }}<br>@endif
        @if ($company['vat_number']){{ __('VAT') }}: {{ $company['vat_number'] }}@endif
    </div>
    <h1>{{ $title }}</h1>
</div>
