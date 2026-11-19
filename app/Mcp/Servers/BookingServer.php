<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ApplyStyleTemplate;
use App\Mcp\Tools\BlockDates;
use App\Mcp\Tools\ChooseLayout;
use App\Mcp\Tools\GetAccommodation;
use App\Mcp\Tools\GetBooking;
use App\Mcp\Tools\GetCalendar;
use App\Mcp\Tools\GetPriceQuote;
use App\Mcp\Tools\GetSiteStyle;
use App\Mcp\Tools\GetStatistics;
use App\Mcp\Tools\ListAccommodations;
use App\Mcp\Tools\ListBookings;
use App\Mcp\Tools\ListPages;
use App\Mcp\Tools\RemoveDateBlock;
use App\Mcp\Tools\SetStyleRule;
use App\Mcp\Tools\UndoStyleChange;
use App\Mcp\Tools\UpdateAccommodationPrices;
use App\Mcp\Tools\UpdateAccommodationText;
use App\Mcp\Tools\UpdatePageText;
use App\Mcp\Tools\UpdateStyle;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The AI connection (routes/ai.php, App\Support\McpConnection): what an AI
 * app such as Claude or ChatGPT may do on this booking website. Each person only gets the tools
 * their rights in the admin allow (BookingTool::shouldRegister).
 */
#[Name('Booking website')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
    This is the admin of a holiday rental booking website: its accommodations, prices, calendar, bookings, statistics, content pages and the look of the website. You act with the rights of the person who connected; you only get the tools they may use.

    - Start with list_accommodations; other tools take an accommodation's id.
    - Amounts are in the accommodation's currency in whole units (145.5 = 145.50).
    - Texts exist per language; the source language is the one the others fall back to. Write texts in the language you are changing.
    - Before changing something, say what you will change and ask for confirmation, unless the person was already explicit.
    - After a price change, check it with get_price_quote. After a change to the look, give the person the website address to look at; undo_style_change puts the previous look back.
    - Payments, refunds, deleting accommodations or bookings, settings and users are not possible here: point the person to the admin.
    - get_booking contains a guest's personal data: only use it for what was asked.
    TEXT)]
class BookingServer extends Server
{
    protected array $tools = [
        // Reading
        ListAccommodations::class,
        GetAccommodation::class,
        GetCalendar::class,
        GetPriceQuote::class,
        ListBookings::class,
        GetBooking::class,
        GetStatistics::class,
        ListPages::class,
        // The look of the website (owner)
        GetSiteStyle::class,
        ApplyStyleTemplate::class,
        UpdateStyle::class,
        ChooseLayout::class,
        SetStyleRule::class,
        UndoStyleChange::class,
        // Content
        UpdateAccommodationText::class,
        UpdateAccommodationPrices::class,
        BlockDates::class,
        RemoveDateBlock::class,
        UpdatePageText::class,
    ];
}
