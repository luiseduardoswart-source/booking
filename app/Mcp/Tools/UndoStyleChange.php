<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Support\StyleHistory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('undo_style_change')]
#[Description('Puts the look of the website back to how it was before the last change made through Claude (style template, style settings, layout or style rule). Can be repeated for earlier changes. The owner can do the same in Settings → Layout & style.')]
class UndoStyleChange extends StyleTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $undone = StyleHistory::undo() ?? throw new ToolFailure('There is no change made through Claude left to undo.');

        $next = StyleHistory::last();

        return Response::text("Undone: {$undone}. The look is back to how it was before that change; see ".url('/').'.'
            .($next !== null ? " The change before that ({$next}) can also be undone." : ''));
    }
}
