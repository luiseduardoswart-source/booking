{{-- File details: rename the file and (for images) edit the alt text --}}
<div class="modal" id="media-details-modal">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog">
        <div class="modal-header">
            <h5 class="text-sm font-semibold"><i class="bi bi-pencil mr-1"></i> {{ __('File details') }}</h5>
            <button type="button" class="icon-btn" data-modal-close><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <div class="mb-4 flex items-center gap-3">
                <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-white/5" id="md-thumb"></div>
                <div class="min-w-0 flex-1">
                    <label class="label" for="md-name">{{ __('File name') }}</label>
                    <div class="flex items-center gap-2">
                        <input type="text" class="input" id="md-name" maxlength="150">
                        <span class="shrink-0 text-sm text-gray-400" id="md-ext"></span>
                    </div>
                    <p class="form-text">{{ __('Spaces and special characters are converted; the extension stays.') }}</p>
                </div>
            </div>

            <div class="hidden" id="md-alt-box">
                <label class="label" for="md-alt">{{ __('Alt text') }}</label>
                <input type="text" class="input" id="md-alt" maxlength="255" placeholder="{{ __('Short description of the image') }}">
                <p class="form-text" id="md-alt-hint"></p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" data-modal-close>{{ __('Cancel') }}</button>
            <button type="button" class="btn-primary" id="md-save"><i class="bi bi-check-lg"></i> {{ __('Save') }}</button>
        </div>
    </div>
</div>
