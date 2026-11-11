<?php

use App\Models\Property;

return [

    /*
    | Livewire's default limit is 12 MB; the photos and videos of an
    | accommodation may be larger. Each field checks its own limit again.
    | This block replaces the entire default block, so the other keys are
    | included with their default value.
    */
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:'.max(Property::MAX_PHOTO_KB, Property::MAX_VIDEO_KB)],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
