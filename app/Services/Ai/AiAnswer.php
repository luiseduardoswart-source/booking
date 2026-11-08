<?php

namespace App\Services\Ai;

/**
 * The answer from an AI service with its usage, for cost tracking
 * (see AiUsage). Cached tokens are counted separately: they are cheaper.
 */
final class AiAnswer
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheWrittenTokens = 0,
        public readonly int $cacheReadTokens = 0,
    ) {}
}
