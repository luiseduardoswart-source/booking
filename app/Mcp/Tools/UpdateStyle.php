<?php

namespace App\Mcp\Tools;

use App\Models\Setting;
use App\Models\User;
use App\Support\SiteTemplates;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_style')]
#[Description('Changes one or more style settings of the website: accent_color (buttons and accents), label_color (photo viewer arrows and current photo), background_color, header_style, font_style and button_style. Give only what changes; "default" puts a setting back to the standard look. See get_site_style for the allowed values.')]
#[IsIdempotent]
class UpdateStyle extends StyleTool
{
    private const COLOURS = ['accent_color', 'label_color', 'background_color'];

    /** @return array<string, string|list<string>> */
    public static function allowedValues(): array
    {
        return [
            ...array_fill_keys(self::COLOURS, 'a colour such as #155e75 or rgb(21, 94, 117), or "default"'),
            ...collect(SiteTemplates::choices())->map(fn (array $values) => [...$values, 'default'])->all(),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = [];

        foreach (self::COLOURS as $field) {
            $fields[$field] = $schema->string()->description('A colour such as #155e75, or "default".');
        }

        foreach (SiteTemplates::choices() as $field => $values) {
            $fields[$field] = $schema->string()->enum([...$values, 'default']);
        }

        return $fields;
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $given = collect(SiteTemplates::FIELDS)
            ->filter(fn (string $field) => $request->get($field) !== null)
            ->mapWithKeys(fn (string $field) => [$field => trim((string) $request->get($field))]);

        if ($given->isEmpty()) {
            throw new ToolFailure('Give at least one style setting to change.');
        }

        $values = $given->map(fn (string $value) => in_array(strtolower($value), ['', 'default'], true) ? null : $value);
        $clean = SiteTemplates::cleanTokens($values->all());

        $invalid = $values->filter(fn (?string $value, string $field) => $value !== null && $clean[$field] === null);

        if ($invalid->isNotEmpty()) {
            throw new ToolFailure('Not allowed: '.$invalid->map(fn ($value, $field) => "{$field} = {$value}")->implode(', ').'. Allowed: '.json_encode(array_intersect_key(self::allowedValues(), $invalid->all())));
        }

        return $this->change('Style settings: '.$given->map(fn ($value, $field) => "{$field} = {$value}")->implode(', '), function () use ($values, $clean) {
            foreach ($values->keys() as $field) {
                Setting::set('frontend', $field, $clean[$field]);
            }

            return 'Changed: '.$values->map(fn ($value, $field) => $field.' = '.($value ?? 'default'))->implode(', ').'.';
        });
    }
}
