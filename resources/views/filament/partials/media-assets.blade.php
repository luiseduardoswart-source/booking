{{-- Everything the media manager needs (ported from devsite's admin layout):
     CDN libraries, translation strings, routes and the modals. Behind @once,
     so both the media page and the picker on Pages can load it. --}}
@once
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/filepond@4.31.4/dist/filepond.min.css" rel="stylesheet">

    @include('filament.partials.media-modal')
    @include('filament.partials.media-preview')
    @include('filament.partials.media-details')
    @include('filament.partials.image-editor')

    <script>
        window.I18N = Object.assign(window.I18N || {}, {
            saved: @json(__('Saved')),
            confirm_delete: @json(__('Are you sure you want to delete this?')),
            folder_name: @json(__('Folder name')),
            edit_image: @json(__('Edit photo')),
            upload_drop: @json(__('Drop files here or <span class="filepond--label-action">browse</span>')),
            preview: @json(__('Preview')),
            preview_unavailable: @json(__('No preview available for this file type — open the file in a new tab.')),
            alt_text: @json(__('Alt text')),
            alt_custom: @json(__('Set manually. Leave empty to use the file name.')),
            alt_from_filename: @json(__('Automatically from the file name. Adjust it for a better description.')),
            crop_size: @json(__('Crop')),
            n_selected: @json(__(':n selected', ['n' => '%n'])),
            confirm_delete_selected: @json(__('Are you sure you want to delete :n items? Folders are removed with all their contents.', ['n' => '%n'])),
        });
        window.ROUTES = {
            mediaList: @json(route('media.list')),
            mediaUpload: @json(route('media.upload')),
            mediaFolder: @json(route('media.folder')),
            mediaDelete: @json(route('media.delete')),
            mediaDeleteMany: @json(route('media.delete-many')),
            mediaRename: @json(route('media.rename')),
            mediaEdit: @json(route('media.edit')),
            mediaAlt: @json(route('media.alt')),
        };
        window.STORAGE_URL = @json(asset('storage'));
    </script>
    @include('filament.partials.sortablejs')
    <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/filepond@4.31.4/dist/filepond.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/mammoth@1.8.0/mammoth.browser.min.js"></script>
    <script src="{{ asset('js/image-resize.js') }}?v={{ filemtime(public_path('js/image-resize.js')) }}"></script>
    <script src="{{ asset('js/media-selection.js') }}?v={{ filemtime(public_path('js/media-selection.js')) }}"></script>
    <script src="{{ asset('js/media-manager.js') }}?v={{ filemtime(public_path('js/media-manager.js')) }}"></script>
@endonce
