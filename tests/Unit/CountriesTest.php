<?php

namespace Tests\Unit;

use App\Support\Countries;
use Tests\TestCase;

class CountriesTest extends TestCase
{
    public function test_countries_are_localized_and_sorted(): void
    {
        $nl = Countries::options('nl');
        $this->assertSame('Nederland', $nl['NL']);
        $this->assertSame('Duitsland', $nl['DE']);

        $en = Countries::options('en');
        $this->assertSame('Netherlands', $en['NL']);
        $this->assertSame('Germany', $en['DE']);

        // Gesorteerd op naam in die taal (eerste alfabetisch).
        $this->assertSame('Afghanistan', array_values($nl)[0]);
    }

    public function test_name_gives_localized_name_or_the_code(): void
    {
        $this->assertSame('Nederland', Countries::name('NL', 'nl'));
        $this->assertSame('France', Countries::name('FR', 'en'));
        $this->assertSame('', Countries::name(null));
        $this->assertSame('XX', Countries::name('XX', 'nl')); // onbekend → code
    }
}
