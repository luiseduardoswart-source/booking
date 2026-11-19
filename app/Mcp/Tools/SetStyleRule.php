<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Support\VisualStyle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_style_rule')]
#[Description('Adjusts the look of one kind of element on the website, like the style editor in the admin: a CSS selector (e.g. "h1" or ".site-header") with properties such as color, font-size or padding. Properties are added to that selector\'s rule; an empty value removes a property; remove = true deletes the whole rule. Only the properties listed by get_site_style are allowed, and no url(), scripts or code.')]
class SetStyleRule extends StyleTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'selector' => $schema->string()->max(300)->description('CSS selector of the element(s), e.g. "h1", ".site-header" or "footer a".')->required(),
            'declarations' => $schema->object()->description('CSS properties and values, e.g. {"font-size": "2.5rem", "color": "#1c1917"}. An empty value removes that property.'),
            'remove' => $schema->boolean()->description('true = delete the whole rule for this selector.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $selector = trim((string) $request->get('selector'));
        $given = $request->get('declarations');
        $given = collect(is_array($given) ? $given : [])
            ->filter(fn ($value) => is_scalar($value) || $value === null)
            ->mapWithKeys(fn ($value, $property) => [strtolower(trim((string) $property)) => trim((string) $value)])
            ->all();
        $remove = (bool) $request->get('remove', false);

        if ($selector === '' || VisualStyle::clean([['selector' => $selector, 'declarations' => ['color' => 'red']]]) === []) {
            throw new ToolFailure('Give a plain CSS selector of the element, without { } < ; or @.');
        }

        if (! $remove && $given === []) {
            throw new ToolFailure('Give at least one CSS property, or remove = true.');
        }

        $rules = VisualStyle::rules();
        $index = collect($rules)->search(fn (array $rule) => $rule['selector'] === $selector);

        if ($remove) {
            if ($index === false) {
                throw new ToolFailure("There is no style rule for \"{$selector}\".");
            }

            return $this->change("Remove style rule {$selector}", function () use ($rules, $index, $selector) {
                unset($rules[$index]);
                VisualStyle::save(array_values($rules));

                return "The style rule for \"{$selector}\" is removed.";
            });
        }

        $declarations = $index === false ? [] : $rules[$index]['declarations'];

        foreach ($given as $property => $value) {
            if ($value === '') {
                unset($declarations[$property]);
            } else {
                $declarations[$property] = $value;
            }
        }

        $clean = VisualStyle::clean([['selector' => $selector, 'declarations' => $declarations]])[0] ?? null;
        $refused = array_keys(array_diff_assoc(array_filter($given, 'strlen'), $clean['declarations'] ?? []));

        if ($refused !== []) {
            throw new ToolFailure('Not allowed: '.implode(', ', $refused).'. Use only these properties, without url(), scripts or the characters { } < > ;: '.implode(', ', VisualStyle::properties()).'.');
        }

        if ($clean === null && $index === false) {
            throw new ToolFailure("There is no style rule for \"{$selector}\" to change.");
        }

        return $this->change("Style rule {$selector}", function () use ($rules, $index, $clean, $selector) {
            if ($clean === null) {
                unset($rules[$index]);
            } elseif ($index === false) {
                $rules[] = $clean;
            } else {
                $rules[$index] = $clean;
            }

            VisualStyle::save(array_values($rules));

            return $clean === null
                ? "The style rule for \"{$selector}\" has no properties left and is removed."
                : "Style rule for \"{$selector}\": ".collect($clean['declarations'])->map(fn ($value, $property) => "{$property}: {$value}")->implode('; ').'.';
        });
    }
}
