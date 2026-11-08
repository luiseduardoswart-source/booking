<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Ai\AiAnswer;
use App\Services\Ai\AiTextGenerator;
use App\Services\Ai\ClaudeTextGenerator;
use App\Services\Ai\FakeAiTextGenerator;
use App\Services\Ai\ListingCopywriter;
use App\Services\Ai\NullAiTextGenerator;
use App\Services\Ai\OpenAiTextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiListingTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_copywriter_delivers_texts_on_base_of_facts(): void
    {
        $copy = (new ListingCopywriter(new FakeAiTextGenerator))
            ->write(['name' => 'Strandhuis', 'city' => 'Zandvoort'], 'nl');

        $this->assertSame('Test korte omschrijving', $copy['short_description']);
        $this->assertStringContainsString('Test beschrijving', $copy['description']);
        $this->assertSame('Test meta-titel', $copy['meta_title']);
        $this->assertSame('Test meta-omschrijving', $copy['meta_description']);
    }

    public function test_copywriter_reads_json_off_a_code_fence(): void
    {
        $generator = new class implements AiTextGenerator
        {
            public function generate(string $systemPrompt, string $userPrompt): string
            {
                return "Hier is het:\n```json\n{\"short_description\": \"Kort\", \"description\": \"Lang\"}\n```";
            }

            public function conversation(string $system, array $messages, ?int $maxTokens = null, ?string $effort = null): AiAnswer
            {
                return new AiAnswer($this->generate($system, end($messages)['content']), 'test');
            }
        };

        $copy = (new ListingCopywriter($generator))->write(['name' => 'Huis'], 'en');

        $this->assertSame('Kort', $copy['short_description']);
        $this->assertSame('Lang', $copy['description']);
        $this->assertNull($copy['meta_title']);
    }

    public function test_chosen_ai_service_determines_the_provider(): void
    {
        Setting::set('ai', 'service', 'claude');
        $this->assertInstanceOf(ClaudeTextGenerator::class, app(AiTextGenerator::class));

        Setting::set('ai', 'service', 'openai');
        $this->assertInstanceOf(OpenAiTextGenerator::class, app(AiTextGenerator::class));

        Setting::set('ai', 'service', 'none');
        $this->assertInstanceOf(NullAiTextGenerator::class, app(AiTextGenerator::class));
    }

    public function test_without_service_gives_a_clear_explanation(): void
    {
        Setting::set('ai', 'service', 'none');

        $this->expectException(\RuntimeException::class);

        app(AiTextGenerator::class)->generate('system', 'user');
    }
}
