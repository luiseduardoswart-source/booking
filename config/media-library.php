<?php

use App\Models\Property;

return [

    /*
    | Spatie's default is 10 MB, smaller than what the Photos tab allows
    | (photos 25 MB, videos 100 MB): such a file was refused anyway. Only
    | this key is set here; the package fills in the rest of its defaults.
    */
    'max_file_size' => max(Property::MAX_PHOTO_KB, Property::MAX_VIDEO_KB) * 1024,

];
