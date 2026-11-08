<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Navigation\PersonalOrder;
use App\Http\Controllers\Controller;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/**
 * Stores the order in which someone dragged the menu on the left, on their
 * own account. An empty list restores the default order.
 */
class MenuOrderController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Check it ourselves: Laravel's auth middleware points to a login route that does not exist here.
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($user->canAccessPanel(Filament::getPanel('admin')), 403);

        // Validate it ourselves: outside api/* Laravel redirects on an error,
        // and the script would see that as a success.
        $validator = Validator::make($request->all(), [
            'order' => ['present', 'array', 'max:100'],
            'order.*' => ['string', 'max:300'],
        ]);
        abort_if($validator->fails(), 422);

        $paths = collect($validator->validated()['order'])
            ->map(fn (string $url) => PersonalOrder::path($url))
            ->unique()
            ->values()
            ->all();

        $user->forceFill(['menu_order' => $paths === [] ? null : $paths])->save();

        return response()->noContent();
    }
}
