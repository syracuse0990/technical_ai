<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepSeekService
{
    protected string $apiKey;

    protected string $baseUrl;

    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('ai.deepseek_api_key');
        $this->baseUrl = config('ai.deepseek_base_url');
        $this->model = config('ai.deepseek_model');
    }

    /**
     * Generate a chat completion using DeepSeek.
     */
    public function chat(string $prompt, array $context = [], float $temperature = 1.0, ?string $systemPrompt = null, array $history = []): string
    {
        $messages = $this->buildMessages($prompt, $context, $systemPrompt, $history);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(60)->post("{$this->baseUrl}/chat/completions", [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
        ]);

        if ($response->failed()) {
            Log::error('DeepSeek API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to get response from DeepSeek API: '.$response->body());
        }

        return $response->json('choices.0.message.content', '');
    }

    /**
     * Stream a chat completion response via SSE.
     */
    public function streamChat(string $prompt, array $context = [], ?string $systemPrompt = null, array $history = []): \Generator
    {
        $messages = $this->buildMessages($prompt, $context, $systemPrompt, $history);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(120)->withOptions([
            'stream' => true,
        ])->post("{$this->baseUrl}/chat/completions", [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => true,
        ]);

        $body = $response->toPsrResponse()->getBody();

        $buffer = '';
        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if (str_starts_with($line, 'data: ')) {
                    $data = substr($line, 6);
                    if ($data === '[DONE]') {
                        return;
                    }
                    $json = json_decode($data, true);
                    $content = $json['choices'][0]['delta']['content'] ?? '';
                    if ($content !== '') {
                        yield $content;
                    }
                }
            }
        }
    }

    /**
     * Classify document text into a topic name.
     */
    public function classifyTopic(string $text, array $existingTopics = []): string
    {
        $cleanText = preg_replace('/[^\P{C}\n\t]/u', '', $text);
        $cleanText = mb_substr($cleanText, 0, 1500);

        if (mb_strlen(trim($cleanText)) < 20) {
            return 'General';
        }

        $topicList = ! empty($existingTopics)
            ? "Existing topics:\n".implode("\n", array_map(fn ($t) => "- {$t}", $existingTopics))
            : 'No existing topics yet.';

        $prompt = <<<PROMPT
Classify this document into a specific topic.

{$topicList}

Rules:
1. Read the document text carefully and determine its MAIN subject.
2. Only reuse an existing topic if the document is CLEARLY about the same specific subject.
3. If the document doesn't clearly fit any existing topic, create a NEW short topic name (2-5 words, Title Case).
4. Be specific — prefer "Rice Pest Management" over a generic "Agriculture" topic.
5. Return ONLY the topic name, nothing else.

Text:
{$cleanText}
PROMPT;

        $response = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a document classification assistant. You only return a short, specific topic name that accurately describes the document content. Return only the topic name, nothing else.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
            ]);

            if ($response->successful()) {
                break;
            }

            if ($response->status() === 429 && $attempt < 3) {
                sleep(3 * $attempt);

                continue;
            }

            break;
        }

        if ($response->failed()) {
            Log::warning('DeepSeek topic classification failed, using fallback', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return 'General';
        }

        $topicName = trim($response->json('choices.0.message.content', 'General'));
        $topicName = trim($topicName, '"\'\'`');
        $topicName = mb_substr($topicName, 0, 100);

        return $topicName ?: 'General';
    }

    /**
     * @param  string[]  $context
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: string, content: string}>
     */
    protected function buildMessages(string $prompt, array $context, ?string $systemPrompt = null, array $history = []): array
    {
        $messages = [];

        $baseInstruction = $systemPrompt
            ? $systemPrompt."\n\n"
            : '';

        $domainExpertise = <<<'DOMAIN'
You are a senior technical AI assistant for LeadsTech — a knowledge platform used by agricultural scientists and field experts specializing in:
- Plant Pathology / Phytopathology (fungal, bacterial, viral diseases)
- Agricultural Entomology (pest identification, IPM, biological control)
- Banana Production & Agronomy (Cavendish, Lakatan, tissue culture, Fusarium TR4/Black Sigatoka)
- Rice Science (varieties, blast, BPH, nutrient management)
- Vegetable & High-Value Crop Production (solanaceous, cucurbits, leafy greens, GAP)
- Crop Consulting & Plant Doctoring (diagnosis, advisory, field scouting)
- Soil Science & Plant Nutrition (fertility, amendments, foliar feeding)

IMPORTANT LANGUAGE RULE: Match the user's language exactly. If the user writes in Tagalog/Filipino, reply entirely in Tagalog/Filipino. If the user writes in English, reply in English. If mixed, follow the dominant language. Never switch languages unless the user does.

Use precise scientific terminology (pathogen names in italics via markdown, active ingredients, varietal names). Assume the user is a domain expert — skip beginner-level explanations unless asked. Provide actionable, field-ready recommendations when applicable. Use markdown formatting (headers, bullet lists, bold, tables) for readability.

FORMATTING RULES:
- Always add a relevant emoji icon before each section header or sub-header to improve visual clarity. Examples: 👤 for person/subscriber info, 📋 for details/summary, 💰 for billing/payments, 📍 for addresses/locations, 📞 for contact info, 🔬 for scientific analysis, 🌾 for crop info, 🐛 for pest info, 💊 for treatments/recommendations, 📊 for data/statistics, ⚠️ for warnings, ✅ for confirmed/positive items, 📄 for documents, 🔧 for technical details, 📝 for notes, 🗓️ for dates/schedules.
- Use **bold** for key values and important terms.
- Use indented bullet points (- or •) with proper hierarchy for structured data. Nest sub-items under parent bullets.
- When listing steps, procedures, or sequential items, use numbered/ordered lists (1. 2. 3.) instead of bullets.
- When listing non-sequential items, attributes, or features, use bullet points (- item).
- Separate sections with clear headers (## or ###) with emoji prefixes.
- Keep paragraphs short. Prefer lists over long prose whenever information can be broken into discrete points.
DOMAIN;

        if (! empty($context)) {
            $contextText = implode("\n\n---\n\n", $context);
            $messages[] = [
                'role' => 'system',
                'content' => $baseInstruction.$domainExpertise."\n\n"
                    ."ACCURACY INSTRUCTIONS:\n"
                    ."- You are provided with multiple document chunks as context. Read ALL of them carefully before answering.\n"
                    ."- Cross-reference information across multiple sources when available. If sources contradict, note the discrepancy.\n"
                    ."- ALWAYS cite the source document name when referencing specific information. Format: **📄 filename**\n"
                    ."- If the context contains the answer, extract it precisely — do not paraphrase loosely.\n"
                    ."- If the context only partially answers the question, clearly state what you found AND what is missing.\n"
                    ."- If the context does NOT contain the answer, say so explicitly. Do NOT fabricate information.\n"
                    ."- When asked about a specific file/image, focus your answer on chunks from that source.\n"
                    ."- Include relevant numbers, dates, names, and specific details from the documents — precision matters.\n"
                    ."\nDocument Context:\n{$contextText}",
            ];
        } else {
            $messages[] = [
                'role' => 'system',
                'content' => $baseInstruction.$domainExpertise."\n\nNo relevant documents were found for this query. Answer from your domain expertise if possible, but inform the user that no matching documents were found in the system. Suggest uploading relevant materials or rephrasing the query.",
            ];
        }

        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        return $messages;
    }
}
