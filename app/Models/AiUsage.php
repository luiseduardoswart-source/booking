<?php

namespace App\Models;

use App\Services\Ai\AiAnswer;
use App\Services\ExchangeRates;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The usage of a single AI call, with the cost at the rate of that
 * moment (config/ai_prices.php). Basis for the overview and the monthly limit.
 */
#[Fillable(['feature', 'provider', 'model', 'input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens', 'cost_eur'])]
class AiUsage extends Model
{
    protected function casts(): array
    {
        return ['cost_eur' => 'float'];
    }

    public static function register(string $function, string $service, AiAnswer $answer): self
    {
        return static::create([
            'feature' => $function,
            'provider' => $service,
            'model' => mb_substr($answer->model, 0, 100),
            'input_tokens' => $answer->inputTokens,
            'output_tokens' => $answer->outputTokens,
            'cache_write_tokens' => $answer->cacheWrittenTokens,
            'cache_read_tokens' => $answer->cacheReadTokens,
            'cost_eur' => static::costsEur($service, $answer),
        ]);
    }

    /** What an answer costs in euros. */
    public static function costsEur(string $service, AiAnswer $answer): float
    {
        $rates = config("ai_prices.text.{$service}", []);
        $rate = $rates[$answer->model] ?? $rates['default'] ?? ['in' => 0, 'out' => 0];
        $cache = config("ai_prices.cache.{$service}", ['write' => 1, 'read' => 1]);

        $dollars = (
            $answer->inputTokens * $rate['in']
            + $answer->cacheWrittenTokens * $rate['in'] * $cache['write']
            + $answer->cacheReadTokens * $rate['in'] * $cache['read']
            + $answer->outputTokens * $rate['out']
        ) / 1_000_000;

        // EUR → USD rate; without a rate, the fixed rate from the config.
        $exchangeRate = ExchangeRates::exchangeRate('USD');

        return $exchangeRate ? $dollars / $exchangeRate : $dollars * (float) config('ai_prices.usd_to_eur');
    }

    /** @return array{count: int, costs: float} calls and costs in euros this month */
    public static function thisMonth(string $function): array
    {
        $row = static::query()
            ->where('feature', $function)
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('count(*) as count, coalesce(sum(cost_eur), 0) as costs')
            ->first();

        return ['count' => (int) $row->count, 'costs' => (float) $row->costs];
    }
}
