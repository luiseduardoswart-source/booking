<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class AiImage extends Model
{
    protected $fillable = ['prompt', 'path', 'status', 'model', 'size', 'month', 'user_id'];

    /** URL of the (pending or media) file on the public disk. */
    public function url(): ?string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : null;
    }
}
