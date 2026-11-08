<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a visitor sent with a form: the answers per field (see FormAnswers),
 * the language they used and whether someone has handled it.
 */
#[Fillable(['form_id', 'data', 'locale', 'handled'])]
class FormSubmission extends Model
{
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'handled' => 'boolean',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** The first email address the visitor gave, to answer them. */
    public function replyAddress(): ?string
    {
        foreach ((array) $this->data as $answer) {
            if (($answer['type'] ?? null) === 'email' && filter_var($answer['value'] ?? null, FILTER_VALIDATE_EMAIL)) {
                return $answer['value'];
            }
        }

        return null;
    }
}
