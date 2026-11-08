<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiImage;
use App\Support\AiImages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AI images (ported from devsite): generated via OpenAI or Google (Nano
 * Banana), with an approval queue; approved images move to the chosen
 * folder in the media manager.
 */
class AiImageController extends Controller implements \Illuminate\Routing\Controllers\HasMiddleware
{
    private const PENDING_DIR = 'ai-pending';

    private const MEDIA_ROOT = 'media';

    /** Only the owner and whoever has the Media manager permission (see Access). */
    public static function middleware(): array
    {
        return [
            function ($request, $next) {
                abort_unless(\App\Support\Access::mayMediaFiles(auth()->user()), 403);

                return $next($request);
            },
        ];
    }

    /** Image format → OpenAI size per model. */
    private function sizeFor(string $model, string $format): string
    {
        $sizes = $model === 'gpt-image-1'
            ? ['square' => '1024x1024', 'landscape' => '1536x1024', 'portrait' => '1024x1536']
            : ['square' => '1024x1024', 'landscape' => '1792x1024', 'portrait' => '1024x1792'];

        return $sizes[$format] ?? '1024x1024';
    }

    /** Image format → Gemini aspect ratio. */
    private function aspectFor(string $format): string
    {
        return ['square' => '1:1', 'landscape' => '16:9', 'portrait' => '9:16'][$format] ?? '1:1';
    }

    /**
     * Calls the active provider and returns the base64 PNG (or throws on an error).
     *
     * @return string base64-image
     */
    private function callProvider(string $prompt, string $format): string
    {
        if (AiImages::provider() === 'gemini') {
            $model = AiImages::model();
            $response = Http::timeout(120)
                ->withHeaders(['x-goog-api-key' => AiImages::key()])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'responseModalities' => ['Image'],
                        'imageConfig' => ['aspectRatio' => $this->aspectFor($format)],
                    ],
                ]);
            if (! $response->successful()) {
                throw new \RuntimeException('Gemini error: '.$response->status().' '.mb_substr($response->body(), 0, 500));
            }
            foreach ($response->json('candidates.0.content.parts', []) as $part) {
                if (! empty($part['inlineData']['data'])) {
                    return $part['inlineData']['data'];
                }
            }
            throw new \RuntimeException('Gemini returned no image.');
        }

        // OpenAI
        $model = AiImages::model();
        $body = ['model' => $model, 'prompt' => $prompt, 'n' => 1, 'size' => $this->sizeFor($model, $format)];
        if ($model === 'dall-e-3') {
            $body['response_format'] = 'b64_json'; // gpt-image-1 returns this by default and does not accept the param
        }
        $response = Http::timeout(120)->withToken(AiImages::key())
            ->post('https://api.openai.com/v1/images/generations', $body);
        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI error: '.$response->status().' '.mb_substr($response->body(), 0, 500));
        }
        $b64 = $response->json('data.0.b64_json');
        if (! $b64) {
            throw new \RuntimeException('OpenAI returned no image.');
        }

        return $b64;
    }

    /** Generates 1–4 images and puts them in the approval queue (pending). */
    public function generate(Request $request)
    {
        abort_unless(AiImages::available(), 404);

        $data = $request->validate([
            'prompt' => 'required|string|max:1000',
            'format' => 'nullable|in:square,landscape,portrait',
            'count' => 'nullable|integer|min:1|max:4',
        ]);

        $count = (int) ($data['count'] ?? 1);
        $format = $data['format'] ?? 'square';
        $provider = AiImages::provider();
        $model = AiImages::model();
        $size = $provider === 'gemini' ? $this->aspectFor($format) : $this->sizeFor($model, $format);

        // enforce the monthly limit (every generation costs money, rejected images too)
        $limit = AiImages::monthlyLimit();
        if ($limit > 0) {
            $used = AiImages::monthlyUsage();
            if ($used >= $limit) {
                return response()->json(['ok' => false, 'message' => __('The monthly limit for AI images has been reached.')], 429);
            }
            $count = min($count, $limit - $used);
        }

        $disk = Storage::disk('public');
        $created = [];

        for ($i = 0; $i < $count; $i++) {
            try {
                $b64 = $this->callProvider($data['prompt'], $format);
                $binary = base64_decode($b64);
                $path = self::PENDING_DIR.'/'.uniqid('ai_', true).'.png';
                $disk->put($path, $binary);

                $img = AiImage::create([
                    'prompt' => $data['prompt'],
                    'path' => $path,
                    'status' => 'pending',
                    'model' => $model,
                    'size' => $size,
                    'month' => now()->format('Y-m'),
                    'user_id' => $request->user()->id,
                ]);
                $created[] = ['id' => $img->id, 'url' => $img->url(), 'prompt' => $img->prompt];
            } catch (\Throwable $e) {
                report($e);
                // first call fails → probably key/config: stop immediately
                if (! $created) {
                    return response()->json(['ok' => false, 'message' => __('Generating failed. Check the API key or try again later.')], 503);
                }
                break;
            }
        }

        if (! $created) {
            return response()->json(['ok' => false, 'message' => __('Generating failed. Check the API key or try again later.')], 503);
        }

        return response()->json(['ok' => true, 'images' => $created, 'usage' => AiImages::monthlyUsage(), 'limit' => $limit]);
    }

    /** Pending images for the queue. */
    public function pending()
    {
        $items = AiImage::where('status', 'pending')->latest()->get()
            ->map(fn ($i) => ['id' => $i->id, 'url' => $i->url(), 'prompt' => $i->prompt])->all();

        return response()->json(['ok' => true, 'images' => $items, 'usage' => AiImages::monthlyUsage(), 'limit' => AiImages::monthlyLimit()]);
    }

    /** Approve: moves the image to the chosen media folder. */
    public function approve(Request $request, AiImage $aiImage)
    {
        abort_unless($aiImage->status === 'pending', 422);

        $data = $request->validate(['folder' => 'nullable|string|max:255']);
        $disk = Storage::disk('public');
        abort_unless($aiImage->path && $disk->exists($aiImage->path), 404);

        // target folder inside media/ (sanitised, no ../)
        $folder = collect(explode('/', str_replace('\\', '/', (string) ($data['folder'] ?? ''))))
            ->filter(fn ($s) => $s !== '' && $s !== '.' && $s !== '..')
            ->implode('/');
        $target = self::MEDIA_ROOT.($folder ? '/'.$folder : '');
        if (! $disk->exists($target)) {
            $disk->makeDirectory($target);
        }

        $base = Str::slug(Str::words($aiImage->prompt, 5, '')) ?: 'ai-image';
        $name = "{$base}.png";
        $i = 1;
        while ($disk->exists("{$target}/{$name}")) {
            $name = "{$base}-{$i}.png";
            $i++;
        }
        $new = "{$target}/{$name}";
        $disk->move($aiImage->path, $new);

        $aiImage->update(['status' => 'approved', 'path' => $new]);

        return response()->json(['ok' => true, 'path' => $new, 'url' => $disk->url($new)]);
    }

    /** Reject: deletes the image (still counts towards the monthly limit). */
    public function reject(AiImage $aiImage)
    {
        if ($aiImage->path) {
            Storage::disk('public')->delete($aiImage->path);
        }
        $aiImage->update(['status' => 'rejected', 'path' => null]);

        return response()->json(['ok' => true]);
    }
}
