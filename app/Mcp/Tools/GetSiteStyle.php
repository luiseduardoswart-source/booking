<?php

namespace App\Mcp\Tools;

use App\Models\Setting;
use App\Models\User;
use App\Support\SiteLayouts;
use App\Support\SiteTemplates;
use App\Support\StyleHistory;
use App\Support\VisualStyle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_site_style')]
#[Description('The current look of the website and every choice there is: the style template, the style settings (colours, header, font, buttons) with their allowed values, the page layout, and the extra style rules per element with the CSS properties they may use. Read this before changing the look.')]
#[IsReadOnly]
class GetSiteStyle extends StyleTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        return Response::structured([
            'website' => url('/'),
            'style_template' => Setting::get('frontend', 'template_name'),
            'style_templates' => collect(SiteTemplates::all())->map(fn (array $template, string $key) => [
                'key' => $key,
                'name' => $template['name'],
                'description' => $template['description'],
            ])->values()->all(),
            'style_settings' => collect(SiteTemplates::FIELDS)->mapWithKeys(fn (string $field) => [$field => Setting::get('frontend', $field)])->all(),
            'style_setting_values' => UpdateStyle::allowedValues(),
            'layout' => SiteLayouts::active(),
            'layouts' => collect(SiteLayouts::all())->map(fn (array $layout, string $key) => ['key' => $key, ...$layout])->values()->all(),
            'style_rules' => VisualStyle::rules(),
            'style_rule_properties' => VisualStyle::properties(),
            'undo_available' => StyleHistory::last(),
        ]);
    }
}
