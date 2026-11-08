<div class="modal" id="media-preview-modal">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <h5 class="min-w-0 truncate text-sm font-semibold" id="mp-name"></h5>
            <button type="button" class="icon-btn" data-modal-close><i class="bi bi-x-lg"></i></button>
        </div>
        {{-- view only: editing happens in the file details window (pencil icon) --}}
        <div class="modal-body" id="mp-content"></div>
        <div class="modal-footer items-center">
            <span class="mr-auto text-xs text-gray-500 dark:text-gray-400" id="mp-meta"></span>
            <a href="#" id="mp-open" target="_blank" class="btn-secondary btn-sm"><i class="bi bi-box-arrow-up-right"></i> {{ __('Open in new tab') }}</a>
            <button type="button" class="btn-secondary" data-modal-close>{{ __('Cancel') }}</button>
        </div>
    </div>
</div>
