<?php

namespace App\Mcp\Tools;

use App\Support\StyleHistory;
use Closure;
use Laravel\Mcp\Response;

/**
 * The look of the website, like Settings → Layout & style and the style
 * editor: for the owner only. Every change first remembers the whole look,
 * so undo_style_change can put it back.
 */
abstract class StyleTool extends BookingTool
{
    protected function permission(): ?array
    {
        return null;
    }

    /**
     * Remembers the look, makes the change and answers with where to look.
     *
     * @param  Closure(): string  $change  makes the change and describes it
     */
    protected function change(string $description, Closure $change): Response
    {
        StyleHistory::remember($description);

        $result = $change();

        return Response::text($result.' Look at the result on '.url('/').' (refresh the page). Not right? undo_style_change puts the previous look back.');
    }
}
