{{-- shared between media-modal (picker) and the media page (inline) — ported from devsite --}}
<div class="mb-2 flex flex-wrap items-center gap-2">
    <div class="mm-breadcrumbs mr-auto text-xs text-gray-500 dark:text-gray-400"></div>
    <label class="btn-primary btn-sm mm-upload">
        <i class="bi bi-upload"></i> {{ __('Upload') }}
        <input type="file" multiple hidden>
    </label>
    <button type="button" class="btn-secondary btn-sm mm-new-folder">
        <i class="bi bi-folder-plus"></i> {{ __('New folder') }}
    </button>
</div>
{{-- search, type filter and view --}}
<div class="mb-3 flex flex-wrap items-center gap-2">
    <div class="relative min-w-44 flex-1">
        <i class="bi bi-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400"></i>
        <input type="search" class="input mm-search !py-1.5 pl-8 text-xs" placeholder="{{ __('Search') }}...">
    </div>
    <select class="input mm-type !w-40 !py-1.5 text-xs">
        <option value="all">{{ __('All files') }}</option>
        <option value="image">{{ __('Images') }}</option>
        <option value="doc">{{ __('Documents') }}</option>
        <option value="video">{{ __('Video') }}</option>
    </select>
    <span class="flex gap-1">
        <button type="button" class="btn-secondary btn-sm mm-view-grid !bg-blue-600 !text-white" title="{{ __('Grid view') }}"><i class="bi bi-grid-3x3-gap"></i></button>
        <button type="button" class="btn-secondary btn-sm mm-view-list" title="{{ __('List view') }}"><i class="bi bi-list-ul"></i></button>
    </span>
</div>
{{-- FilePond drag & drop multi-upload --}}
<div class="mm-pond mb-3">
    <input type="file" multiple>
</div>
{{-- delete several at once; only visible on the media page, not in the picker --}}
<div class="mm-bulk mb-2 hidden flex-wrap items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10">
    <label class="flex cursor-pointer items-center gap-2 text-sm">
        <input type="checkbox" class="checkbox mm-bulk-all">
        {{ __('Select all') }}
    </label>
    <span class="text-sm font-medium text-blue-700 dark:text-blue-300 mm-bulk-count"></span>
    <span class="ml-auto flex gap-2 mm-bulk-actions hidden">
        <button type="button" class="btn-secondary btn-sm mm-bulk-clear">{{ __('Clear selection') }}</button>
        <button type="button" class="btn-danger btn-sm mm-bulk-delete">
            <i class="bi bi-trash"></i> {{ __('Delete selection') }}
        </button>
    </span>
</div>
<div class="media-grid media-grid-view"></div>
<div class="mm-empty hidden py-10 text-center text-sm text-gray-400">{{ __('This folder is empty') }}</div>
