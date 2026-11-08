<div class="modal" id="media-modal">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog modal-xl">
        <div class="modal-header">
            <h5 class="text-sm font-semibold"><i class="bi bi-folder2-open mr-1"></i> {{ __('Media manager') }}</h5>
            <button type="button" class="icon-btn" data-modal-close><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            @include('filament.partials.media-browser')
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" data-modal-close>{{ __('Cancel') }}</button>
            <button type="button" class="btn-primary mm-select-btn hidden">{{ __('Select') }}</button>
        </div>
    </div>
</div>
