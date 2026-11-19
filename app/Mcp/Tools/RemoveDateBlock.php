<?php

namespace App\Mcp\Tools;

use App\Enums\CalendarBlockType;
use App\Models\CalendarBlock;
use App\Models\User;
use App\Support\Access;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('remove_date_block')]
#[Description('Frees blocked nights again by removing a block made by hand (the id comes from get_calendar). Blocks from a calendar synchronisation (Airbnb, Booking.com) are not removed here: they come back from the other site.')]
#[IsDestructive]
class RemoveDateBlock extends BookingTool
{
    protected function permission(): ?array
    {
        return ['calendar', Access::DELETE];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'block_id' => $schema->integer()->description('The id of the block, from get_calendar.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $request->validate(['block_id' => ['required', 'integer']]);

        $block = Access::restrict(CalendarBlock::query(), $user)->with('property:id,name')->find((int) $request->get('block_id'))
            ?? throw new ToolFailure('No block with that id found.');

        if ($block->type === CalendarBlockType::External) {
            throw new ToolFailure('This block comes from a calendar synchronisation; change it on the other site.');
        }

        $block->delete();

        return Response::text("Block removed: the nights {$block->start_date->toDateString()} to {$block->end_date->toDateString()} of {$block->property?->name} are free again (unless something else takes them).");
    }
}
