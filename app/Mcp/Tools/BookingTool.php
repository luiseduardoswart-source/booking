<?php

namespace App\Mcp\Tools;

use App\Filament\Resources\Properties\PropertyResource;
use App\Models\Property;
use App\Models\User;
use App\Support\Access;
use App\Support\Languages;
use App\Support\RichText;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Every action of the AI connection. The rights are those of the admin
 * (App\Support\Access): a tool needs one screen and action from
 * Access::SCREENS, or is for the owner only (permission() returns null).
 * Who may not use a tool does not see it, and cannot call it.
 *
 * The user is the real User (AuthenticateMcpUser), so a host only finds
 * their own accommodations, saving runs the same ownership check as the
 * admin, and the activity log records who changed what.
 */
abstract class BookingTool extends Tool
{
    /**
     * The screen and action this tool needs, e.g. ['bookings', Access::VIEW];
     * null = only the owner.
     *
     * @return array{0: string, 1: string}|null
     */
    abstract protected function permission(): ?array;

    abstract protected function run(Request $request, User $user): Response|ResponseFactory;

    public function shouldRegister(Request $request): bool
    {
        return $this->allows($request->user());
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->allows($user)) {
            return Response::error('Your account does not have permission for this.');
        }

        try {
            return DB::transaction(fn () => $this->run($request, $user));
        } catch (ToolFailure|AuthorizationException $e) {
            return Response::error($e->getMessage());
        }
    }

    protected function allows(mixed $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $permission = $this->permission();

        return $permission === null ? $user->isAdministrator() : Access::may($user, ...$permission);
    }

    /**
     * An accommodation the user may see, by id, address (slug) or exact name.
     */
    protected function accommodation(User $user, mixed $reference): Property
    {
        $reference = trim((string) $reference);

        $found = $reference === '' ? null : Access::restrict(Property::query(), $user)
            ->where(fn ($query) => $query
                ->when(ctype_digit($reference), fn ($q) => $q->orWhere('properties.id', (int) $reference))
                ->orWhere('slug', $reference)
                ->orWhere('name', $reference))
            ->first();

        return $found ?? throw new ToolFailure("No accommodation \"{$reference}\" found. Use list_accommodations for the ids.");
    }

    /** The schema field that names an accommodation, the same in every tool. */
    protected function accommodationField(JsonSchema $schema): Type
    {
        return $schema->string()->description('The accommodation: its id, address (slug) or exact name, from list_accommodations.');
    }

    /** Short identification of an accommodation in every answer. */
    protected function summaryOf(Property $property): array
    {
        return [
            'id' => $property->id,
            'name' => $property->name,
            'url' => url($property->slug),
            'admin_url' => PropertyResource::canEdit($property) ? PropertyResource::getUrl('edit', ['record' => $property]) : null,
        ];
    }

    /**
     * A translatable text in every language of the website, as plain text
     * (null = that language shows the source text).
     *
     * @return array<string, ?string>
     */
    protected static function textPerLanguage(Model $model, string $field): array
    {
        return collect(Languages::available())
            ->mapWithKeys(fn (string $locale) => [$locale => RichText::toPlainText($model->ownText($field, $locale))])
            ->all();
    }

    /** An amount in cents as a decimal in the currency's major unit (12345 → 123.45). */
    protected static function amount(?int $cents): ?float
    {
        return $cents === null ? null : round($cents / 100, 2);
    }
}
