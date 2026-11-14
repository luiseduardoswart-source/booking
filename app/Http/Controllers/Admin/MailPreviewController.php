<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MailType;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Filament\Resources\Properties\PropertyResource;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Support\Access;
use App\Support\Languages;
use App\Support\MailPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The standard email behind the info button at a custom mail text, shown in a
 * frame in the modal. A request of its own: rendered inside a Livewire
 * component, Blade adds markers to every @if that break the email's Markdown.
 * Behind the panel login, with the same rights as the "Automatic emails" tab.
 */
class MailPreviewController extends Controller
{
    public function __invoke(Request $request, int $property, string $type, string $locale): Response
    {
        $mailType = MailType::tryFrom($type);
        abort_if($mailType === null || ! in_array($locale, Languages::available(), true), 404);

        $accommodation = Property::withoutGlobalScope('withoutMaster')->findOrFail($property);
        $user = $request->user();

        abort_unless($accommodation->is_master
            ? EditDefaults::canAccess()
            : PropertyResource::canEdit($accommodation) && Access::may($user, 'payment_rules', Access::EDIT), 403);

        return response(MailPreview::html($accommodation, $mailType, $locale))
            ->header('Cache-Control', 'no-store');
    }
}
