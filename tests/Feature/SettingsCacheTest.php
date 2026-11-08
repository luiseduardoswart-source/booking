<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Setting;
use App\Support\Languages;
use App\Support\RequestMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Settings and languages come from the cache once per request. Previously
 * every lookup cost a query, and a missing setting even cost three on every
 * page: that made the admin up to 10 seconds slow live.
 */
class SettingsCacheTest extends TestCase
{
    use RefreshDatabase;

    /** Number of queries that $do runs. */
    private function countQueries(callable $do): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $do();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** Starts like a new request: nothing left in the request memory. */
    private function newRequest(): void
    {
        RequestMemory::forgets('settings.all');
        RequestMemory::forgets('languages.all');
    }

    public function test_reading_many_settings_costs_at_most_one_query(): void
    {
        Setting::set('company', 'name', 'Strandhuis BV');
        $this->newRequest();

        $count = $this->countQueries(function () {
            foreach (range(1, 50) as $i) {
                Setting::get('company', 'name');
                Setting::get('company', "exists_not_{$i}", 'default');
            }
        });

        $this->assertLessThanOrEqual(1, $count);
    }

    public function test_a_missing_setting_costs_no_extra_queries_on_the_next_request(): void
    {
        Setting::get('company', 'exists_not');
        $this->newRequest();

        // The database cache costs one query for the whole list, regardless of how many are missing.
        $this->assertLessThanOrEqual(1, $this->countQueries(fn () => Setting::get('company', 'exists_not')));
        $this->assertSame('default', Setting::get('company', 'exists_not', 'default'));
    }

    public function test_a_change_is_visible_immediately_also_within_the_same_request(): void
    {
        $this->assertNull(Setting::get('company', 'name'));

        Setting::set('company', 'name', 'Strandhuis BV');
        $this->assertSame('Strandhuis BV', Setting::get('company', 'name'));

        $this->newRequest();
        $this->assertSame('Strandhuis BV', Setting::get('company', 'name'));

        Setting::set('company', 'name', null);
        $this->assertSame('val terug', Setting::get('company', 'name', 'val terug'));
    }

    public function test_the_language_list_comes_from_the_cache_once_per_request(): void
    {
        Languages::active(); // the migration already sets up the default languages
        $this->newRequest();

        $count = $this->countQueries(function () {
            foreach (range(1, 30) as $i) {
                Languages::active();
                Languages::default();
            }
        });

        $this->assertLessThanOrEqual(2, $count); // languages + settings, each at most once

        // A new language is visible immediately, also within the same request.
        $this->assertNotContains('pt', Languages::active());
        Language::create(['code' => 'pt', 'name' => 'Português', 'active' => true, 'sort' => 99]);
        $this->assertContains('pt', Languages::active());
    }
}
