<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * KimiService — used exclusively for image/vision processing.
 * Text chat and classification are handled by DeepSeekService (cheaper).
 */
class KimiService
{
    protected string $apiKey;

    protected string $baseUrl;

    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('ai.kimi_api_key');
        $this->baseUrl = config('ai.kimi_base_url');
        $this->model = config('ai.kimi_model');
    }

    /**
     * Extract text from an image using KIMI k2.5 multimodal vision.
     */
    public function extractTextFromImage(string $filePath): string
    {
        $imageData = base64_encode(file_get_contents($filePath));
        $mimeType = mime_content_type($filePath) ?: 'image/png';

        $response = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(120)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a document text extraction assistant. Extract ALL text content from the image exactly as it appears. Preserve the structure, headings, bullet points, and formatting. Return only the extracted text, nothing else.',
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => "data:{$mimeType};base64,{$imageData}",
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => 'Extract all the text from this image. Return only the text content.',
                            ],
                        ],
                    ],
                ],
                'temperature' => 1.0,
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
            Log::error('KIMI vision extraction failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to extract text from image via KIMI vision: '.$response->body());
        }

        return $response->json('choices.0.message.content', '');
    }

    /**
     * Analyze an image: describe visual content AND extract any text.
     */
    public function analyzeImage(string $filePath): string
    {
        $imageData = base64_encode(file_get_contents($filePath));
        $mimeType = mime_content_type($filePath) ?: 'image/png';

        $response = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(120)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an image analysis assistant. For every image, provide TWO sections:

1. **DESCRIPTION**: Describe what you see in detail — identify objects, text, diagrams, charts, people, or any visual elements.

2. **TEXT CONTENT**: If the image contains any text (signs, labels, documents, handwriting, etc.), extract it exactly as it appears. If there is no text, write "None".

Always provide both sections, even if one is brief.',
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => "data:{$mimeType};base64,{$imageData}",
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => 'Analyze this image. Describe everything you see and extract any text if present.',
                            ],
                        ],
                    ],
                ],
                'temperature' => 1.0,
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
            Log::error('KIMI image analysis failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to analyze image via KIMI vision: '.$response->body());
        }

        return $response->json('choices.0.message.content', '');
    }

    /**
     * Describe the visual content of an image using KIMI k2.5 multimodal vision.
     */
    public function describeImage(string $filePath): string
    {
        $imageData = base64_encode(file_get_contents($filePath));
        $mimeType = mime_content_type($filePath) ?: 'image/png';

        $response = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(120)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an image analysis assistant. Describe the image in detail: what objects, people, animals, plants, scenery, text, colors, and actions are visible. Be thorough and factual.',
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => "data:{$mimeType};base64,{$imageData}",
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => 'Describe everything you see in this image in detail.',
                            ],
                        ],
                    ],
                ],
                'temperature' => 1.0,
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
            Log::error('KIMI image description failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to describe image via KIMI vision: '.$response->body());
        }

        return $response->json('choices.0.message.content', '');
    }

    /**
     * Upload a file to KIMI's file API for content extraction.
     */
    public function uploadFile(string $filePath, string $mimeType = 'application/octet-stream'): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
        ])->timeout(120)->attach(
            'file',
            file_get_contents($filePath),
            basename($filePath),
            ['Content-Type' => $mimeType]
        )->post("{$this->baseUrl}/files", [
            'purpose' => 'file-extract',
        ]);

        if ($response->failed()) {
            Log::error('KIMI file upload failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to upload file to KIMI: '.$response->body());
        }

        $fileId = $response->json('id');
        if (! $fileId) {
            throw new \RuntimeException('KIMI file upload returned no file ID.');
        }

        return $fileId;
    }

    /**
     * Extract text content from an uploaded file using KIMI's file-extract API.
     */
    public function extractTextFromFile(string $fileId): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
        ])->timeout(120)->get("{$this->baseUrl}/files/{$fileId}/content");

        if ($response->failed()) {
            Log::error('KIMI file content extraction failed', [
                'file_id' => $fileId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to extract file content from KIMI: '.$response->body());
        }

        return $response->json('content', $response->body());
    }
}
