<?php

namespace App\Mcp\Tools;

use App\Models\Setting;
use App\Models\User;
use App\Support\SiteLayouts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('choose_layout')]
#[Description('Chooses the page layout of the website from the layouts installed (see get_site_style), for example the classic layout or a full-screen one. Installing a new layout is only possible in the admin.')]
#[IsIdempotent]
class ChooseLayout extends StyleTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'layout' => $schema->string()->enum(array_keys(SiteLayouts::all()))->description('The key of the layout.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $key = (string) $request->get('layout');
        $layout = SiteLayouts::all()[$key] ?? throw new ToolFailure("No layout \"{$key}\". Choose from: ".implode(', ', array_keys(SiteLayouts::all())).'.');

        return $this->change("Layout {$layout['name']}", function () use ($key, $layout) {
            Setting::set('frontend', 'layout', $key);

            return "The website now uses the layout \"{$layout['name']}\".";
        });
    }
}
