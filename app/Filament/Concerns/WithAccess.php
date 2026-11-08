<?php

namespace App\Filament\Concerns;

use App\Support\Access;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Permissions for a Filament resource from App\Support\Access. Filament
 * routes every check (view, create, edit, delete, also in bulk) through
 * getAuthorizationResponse(); we answer it here in one place.
 * getEloquentQuery() makes sure a host only sees their own data, also in
 * search results and when opening a record via the URL.
 */
trait WithAccess
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $actionName = $action instanceof UnitEnum ? $action->name : $action;

        $permission = match ($actionName) {
            'viewAny', 'view' => Access::VIEW,
            'create', 'replicate' => Access::CREATE,
            'update', 'reorder' => Access::EDIT,
            'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny' => Access::DELETE,
            default => null,
        };

        $allowed = $permission !== null
            && Access::may(auth()->user(), (string) Access::screenOf(static::class), $permission)
            && ($record === null || Access::isOwn($record));

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        return Access::restrict(parent::getEloquentQuery());
    }
}
