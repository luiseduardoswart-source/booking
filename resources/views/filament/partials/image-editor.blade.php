<div class="modal" id="image-editor-modal">
    <div class="modal-backdrop"></div>
    <div class="modal-dialog modal-xl">
        <div class="modal-header">
            <h5 class="text-sm font-semibold"><i class="bi bi-magic mr-1"></i> {{ __('Edit photo') }}</h5>
            <button type="button" class="icon-btn" data-modal-close><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            {{-- aspect ratios + transformations --}}
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Aspect ratio') }}:</span>
                @foreach (['free' => __('Free'), '1' => '1:1', '1.3333' => '4:3', '1.7778' => '16:9', '1.5' => '3:2', '0.6667' => '2:3', '3' => '3:1 banner'] as $ratio => $label)
                    <button type="button" class="btn-secondary btn-sm ie-aspect" data-aspect="{{ $ratio }}">{{ $label }}</button>
                @endforeach
                <span class="mx-2 h-5 w-px bg-gray-300 dark:bg-gray-600"></span>
                <button type="button" class="btn-secondary btn-sm" data-ie="rotate-left" title="{{ __('Rotate left') }}"><i class="bi bi-arrow-counterclockwise"></i></button>
                <button type="button" class="btn-secondary btn-sm" data-ie="rotate-right" title="{{ __('Rotate right') }}"><i class="bi bi-arrow-clockwise"></i></button>
                <button type="button" class="btn-secondary btn-sm" data-ie="flip-h" title="{{ __('Flip horizontal') }}"><i class="bi bi-symmetry-vertical"></i></button>
                <button type="button" class="btn-secondary btn-sm" data-ie="flip-v" title="{{ __('Flip vertical') }}"><i class="bi bi-symmetry-horizontal"></i></button>
                <button type="button" class="btn-secondary btn-sm" data-ie="reset" title="{{ __('Reset') }}"><i class="bi bi-arrow-repeat"></i> {{ __('Reset') }}</button>

                {{-- size: enter the dimensions yourself, the lock keeps the aspect ratio --}}
                <span class="mx-2 h-5 w-px bg-gray-300 dark:bg-gray-600"></span>
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" title="{{ __('The size the photo is saved at. With the lock on, the other side follows automatically.') }}">{{ __('Size') }}:</span>
                <label class="sr-only" for="ie-width">{{ __('Width') }}</label>
                <input type="number" id="ie-width" class="input !w-20 !py-1 text-sm" min="1" max="4096" step="1" inputmode="numeric" title="{{ __('Width') }}">
                <span class="text-xs text-gray-500 dark:text-gray-400">&times;</span>
                <label class="sr-only" for="ie-height">{{ __('Height') }}</label>
                <input type="number" id="ie-height" class="input !w-20 !py-1 text-sm" min="1" max="4096" step="1" inputmode="numeric" title="{{ __('Height') }}">
                <span class="text-xs text-gray-500 dark:text-gray-400">px</span>
                <button type="button" class="btn-secondary btn-sm" id="ie-lock" aria-pressed="true" title="{{ __('Keep aspect ratio') }}">
                    <i class="bi bi-lock-fill"></i>
                </button>
                <span class="text-xs text-gray-400" id="ie-size-hint"></span>
            </div>

            {{-- canvas --}}
            <div class="ie-stage max-h-[55vh] overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-900" style="height: 55vh;">
                <img id="ie-image" class="block max-w-full" alt="">
            </div>

            {{-- effects --}}
            <div class="mt-4 grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (['brightness' => [__('Brightness'), 0, 200, 100], 'contrast' => [__('Contrast'), 0, 200, 100], 'saturate' => [__('Saturation'), 0, 200, 100], 'grayscale' => [__('Black & white'), 0, 100, 0], 'sepia' => [__('Sepia'), 0, 100, 0], 'blur' => [__('Blur'), 0, 10, 0]] as $fx => [$label, $min, $max, $default])
                    <label class="flex items-center gap-3 text-sm">
                        <span class="w-24 shrink-0">{{ $label }}</span>
                        <input type="range" class="ie-fx w-full accent-blue-600" data-fx="{{ $fx }}"
                               min="{{ $min }}" max="{{ $max }}" value="{{ $default }}" data-default="{{ $default }}">
                    </label>
                @endforeach
            </div>
        </div>
        <div class="modal-footer items-center">
            <label class="mr-auto flex items-center gap-2 text-sm">
                <input type="checkbox" class="checkbox" id="ie-overwrite">
                {{ __('Overwrite the original (otherwise a copy is saved)') }}
            </label>
            <button type="button" class="btn-secondary" data-modal-close>{{ __('Cancel') }}</button>
            <button type="button" class="btn-primary" id="ie-save"><i class="bi bi-check-lg"></i> {{ __('Save') }}</button>
        </div>
    </div>
</div>
