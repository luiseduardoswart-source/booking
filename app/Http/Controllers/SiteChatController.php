<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Services\Ai\SiteChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Answers a question from the chat on the site. */
class SiteChatController extends Controller
{
    public function question(Request $request, SiteChat $chat): JsonResponse
    {
        abort_unless(SiteChat::available(), 404);

        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:'.(SiteChat::MAX_MESSAGES * 2)],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:'.SiteChat::MAX_CHARACTERS],
            'accommodation' => ['nullable', 'integer'],
        ]);

        // The accommodation of the page the chat is open on; unknown or offline = the whole site.
        $accommodation = isset($data['accommodation'])
            ? Property::query()->where('active', true)->find($data['accommodation'])
            : null;

        // Only the last part of the conversation, and the last message must be from the visitor.
        $messages = array_values(array_map(
            fn (array $message) => ['role' => $message['role'], 'content' => $message['content']],
            array_slice($data['messages'], -SiteChat::MAX_MESSAGES),
        ));

        if (end($messages)['role'] !== 'user') {
            return response()->json(['ok' => false, 'message' => __('Something went wrong, please try again.')], 422);
        }

        // A conversation starts with the visitor; a first reply (the welcome message) is not included.
        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        try {
            return response()->json(['ok' => true, 'reply' => $chat->answer($messages, $accommodation)]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => __('The chat is unavailable right now. Please try again later or use the contact form.')], 503);
        }
    }
}
