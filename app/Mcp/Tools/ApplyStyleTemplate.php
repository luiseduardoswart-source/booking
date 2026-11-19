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

#[Name('apply_style_template')]
#[Description('Gives the website one of the ready-made style templates: a set of colours, header, font and button shape that fit together, as in Settings → Layout & style. The page layout stays the same. See get_site_style for the templates.')]
#[IsIdempotent]
class ApplyStyleTemplate extends StyleTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'template' => $schema->string()->enum(array_keys(SiteTemplates::all()))->description('The key of the style template.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $key = (string) $request->get('template');
        $template = SiteTemplates::all()[$key] ?? throw new ToolFailure("No style template \"{$key}\". Choose from: ".implode(', ', array_keys(SiteTemplates::all())).'.');

        return $this->change("Style template {$template['name']}", function () use ($key, $template) {
            foreach (SiteTemplates::cleanTokens($template['tokens']) as $field => $value) {
                Setting::set('frontend', $field, $value);
            }

            Setting::set('frontend', 'template_name', $key);

            return "The website now uses the style template \"{$template['name']}\".";
        });
    }
}
