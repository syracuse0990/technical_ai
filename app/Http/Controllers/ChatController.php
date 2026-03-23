<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\File;
use App\Models\Message;
use App\Services\DeepSeekService;
use App\Services\KimiService;
use App\Services\VectorSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        $conversations = Conversation::where('user_id', $request->user()->id)
            ->latest()
            ->limit(30)
            ->get(['id', 'title', 'created_at']);

        return Inertia::render('Chat/Index', [
            'conversations' => $conversations,
            'activeConversation' => null,
            'messages' => [],
        ]);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $conversations = Conversation::where('user_id', $request->user()->id)
            ->latest()
            ->limit(30)
            ->get(['id', 'title', 'created_at']);

        $messages = $conversation->messages()->orderBy('created_at')->get();

        return Inertia::render('Chat/Index', [
            'conversations' => $conversations,
            'activeConversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    public function startConversation(Request $request): RedirectResponse
    {
        $conversation = Conversation::create([
            'user_id' => $request->user()->id,
            'topic_id' => null,
            'title' => 'New Conversation',
        ]);

        return redirect()->route('chat.show', $conversation);
    }

    public function sendMessage(
        Request $request,
        Conversation $conversation,
        VectorSearchService $vectorSearch,
        DeepSeekService $deepSeek,
    ): RedirectResponse {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $validated['message'],
        ]);

        $results = $vectorSearch->search($validated['message'], null, $request->user()->id);

        Log::info('Chat search results', [
            'conversation_id' => $conversation->id,
            'query' => $validated['message'],
            'results_count' => count($results),
        ]);

        $context = array_map(fn ($r) => "[Source: {$r['source']}]\n{$r['content']}", $results);

        $history = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        $response = $deepSeek->chat($validated['message'], $context, 1.0, $conversation->system_prompt, $history);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $response,
        ]);

        if ($conversation->messages()->count() <= 2 && $conversation->title === 'New Conversation') {
            $conversation->update([
                'title' => mb_substr($validated['message'], 0, 80),
            ]);
        }

        return redirect()->route('chat.show', $conversation);
    }

    public function stream(
        Request $request,
        Conversation $conversation,
        VectorSearchService $vectorSearch,
        DeepSeekService $deepSeek,
    ): StreamedResponse {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:5000',
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:10240'],
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $validated['message'],
        ]);

        // Analyze image via KIMI vision if attached
        $imageAnalysis = null;
        if ($request->hasFile('image')) {
            try {
                $kimiService = app(KimiService::class);
                $imageAnalysis = $kimiService->analyzeImage($request->file('image')->getRealPath());

                Log::info('Image analyzed via KIMI vision', [
                    'conversation_id' => $conversation->id,
                    'image_name' => $request->file('image')->getClientOriginalName(),
                    'analysis_length' => mb_strlen($imageAnalysis),
                ]);
            } catch (\Throwable $e) {
                Log::error('KIMI image analysis failed in chat', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $isFileSearch = $this->isFileSearchIntent($validated['message']);
        $matchedFiles = [];

        if ($isFileSearch) {
            $matchedFiles = $this->searchFiles($validated['message'], $request->user()->id);
        }

        $results = $vectorSearch->search($validated['message'], null, $request->user()->id);

        Log::info('Chat stream search results', [
            'conversation_id' => $conversation->id,
            'query' => $validated['message'],
            'results_count' => count($results),
            'is_file_search' => $isFileSearch,
            'matched_files' => count($matchedFiles),
        ]);

        $sourceFileIds = collect($results)->pluck('file_id')->unique()->filter()->values()->all();
        $sourceFiles = ! empty($sourceFileIds)
            ? File::whereIn('id', $sourceFileIds)
                ->get(['id', 'original_name', 'mime_type', 'file_size'])
                ->toArray()
            : [];

        // Only show file cards for explicit file search requests,
        // not for every query that happens to use vector search context
        $allFiles = $isFileSearch
            ? collect(array_merge($matchedFiles, $sourceFiles))
                ->unique('id')
                ->values()
                ->all()
            : [];

        $context = array_map(fn ($r) => "[Source: {$r['source']}]\n{$r['content']}", $results);

        if ($imageAnalysis) {
            array_unshift($context, "[Attached Image Analysis]\n{$imageAnalysis}");
        }

        if ($isFileSearch && ! empty($matchedFiles)) {
            $fileList = array_map(
                fn ($f) => "- {$f['original_name']} (ID: {$f['id']}, Type: {$f['mime_type']}, Size: ".number_format($f['file_size'] / 1024, 1).' KB)',
                $matchedFiles
            );
            $context[] = "[File Search Results]\nThe following files match the user's request:\n".implode("\n", $fileList);
        }

        $history = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        if ($conversation->messages()->count() <= 1 && $conversation->title === 'New Conversation') {
            $conversation->update([
                'title' => mb_substr($validated['message'], 0, 80),
            ]);
        }

        return response()->stream(function () use ($deepSeek, $validated, $context, $conversation, $history, $allFiles) {
            if (! empty($allFiles)) {
                echo 'data: '.json_encode(['files' => $allFiles])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }

            $fullResponse = '';

            foreach ($deepSeek->streamChat($validated['message'], $context, $conversation->system_prompt, $history) as $chunk) {
                $fullResponse .= $chunk;
                echo 'data: '.json_encode(['chunk' => $chunk])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }

            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $fullResponse,
                'metadata' => ! empty($allFiles) ? json_encode(['files' => $allFiles]) : null,
            ]);

            echo "data: [DONE]\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function destroyConversation(Request $request, Conversation $conversation): RedirectResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $conversation->delete();

        return redirect()->route('chat.index');
    }

    public function feedback(Request $request, Message $message): JsonResponse
    {
        $validated = $request->validate([
            'feedback' => 'required|in:up,down,null',
        ]);

        $message->update([
            'feedback' => $validated['feedback'] === 'null' ? null : $validated['feedback'],
        ]);

        return response()->json(['ok' => true]);
    }

    public function updateSystemPrompt(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'system_prompt' => 'nullable|string|max:2000',
        ]);

        $conversation->update([
            'system_prompt' => $validated['system_prompt'] ?: null,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Detect if the user is asking to find/search for a specific file.
     */
    protected function isFileSearchIntent(string $message): bool
    {
        $patterns = [
            '/\b(find|search|look\s+for|get|give|show|locate|hanapin|pakita|ibigay|pakuha)\b.*(file|document|pdf|paper|report|dokumento|archivo)/i',
            '/\b(file|document|pdf|paper|report|dokumento)\b.*(for|about|on|regarding|tungkol|para)\b/i',
            '/\bwhere\s+(is|are|can\s+i\s+find)\b.*(file|document)/i',
            '/\bnasaan\b.*(file|document|dokumento)/i',
            '/\bdo\s+(you|we)\s+have\b.*(file|document)/i',
            '/\bmeron\s+(ba|bang)\b.*(file|document|dokumento)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Search files by name and chunk content.
     *
     * @return array<int, array{id: int, original_name: string, mime_type: string, file_size: int}>
     */
    protected function searchFiles(string $query, int $userId): array
    {
        $stopWords = ['find', 'search', 'get', 'give', 'show', 'me', 'the', 'a', 'an', 'for',
            'about', 'file', 'files', 'document', 'documents', 'pdf', 'hanapin', 'pakita',
            'ibigay', 'ko', 'ang', 'mga', 'na', 'ng', 'sa', 'yung', 'po', 'naman',
            'dokumento', 'archivo', 'please', 'can', 'you', 'where', 'is', 'are', 'do', 'we', 'have',
            'look', 'locate', 'paper', 'report', 'meron', 'ba', 'bang', 'nasaan', 'pakuha'];

        $words = preg_split('/\s+/', mb_strtolower(trim($query)));
        $keywords = array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 2 && ! in_array($w, $stopWords)));

        if (empty($keywords)) {
            return [];
        }

        $queryBuilder = File::query()
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhere('visibility', 'public');
            })
            ->where('status', 'completed');

        $queryBuilder->where(function ($q) use ($keywords) {
            foreach ($keywords as $kw) {
                $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $kw);
                $q->orWhere('original_name', 'ILIKE', "%{$escaped}%");
            }
        });

        return $queryBuilder
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'original_name', 'mime_type', 'file_size'])
            ->toArray();
    }
}
