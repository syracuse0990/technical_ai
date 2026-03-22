<?php

namespace App\Jobs;

use App\Models\DocumentChunk;
use App\Models\File;
use App\Models\Topic;
use App\Services\ChunkingService;
use App\Services\DeepSeekService;
use App\Services\EmbeddingService;
use App\Services\TextExtractorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 300;

    public function __construct(protected File $file)
    {
        $this->onQueue('default');
    }

    public function handle(
        TextExtractorService $extractor,
        ChunkingService $chunker,
        EmbeddingService $embedder,
        DeepSeekService $deepSeek,
    ): void {
        ini_set('memory_limit', '512M');

        // Skip AI processing for file types that can't be read/extracted
        if ($this->isUnreadableFile()) {
            $this->file->update(['status' => 'completed']);

            Log::info('Skipped AI processing for unreadable file type', [
                'file_id' => $this->file->id,
                'mime_type' => $this->file->mime_type,
                'original_name' => $this->file->original_name,
            ]);

            return;
        }

        $this->file->update(['status' => 'processing']);

        try {
            // 1. Extract text — download from Wasabi to a temp file
            $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'doc_'.uniqid().'_'.$this->file->filename;
            $stream = Storage::disk('wasabi')->readStream($this->file->file_path);
            file_put_contents($tempPath, $stream);

            try {
                $text = $extractor->extract($tempPath, $this->file->mime_type);
            } finally {
                @unlink($tempPath);
            }

            if (empty(trim($text))) {
                throw new \RuntimeException('No text could be extracted from the document.');
            }

            Log::info('Text extracted from file', [
                'file_id' => $this->file->id,
                'text_length' => mb_strlen($text),
            ]);

            // 2. Auto-classify into a topic (skip if no API key)
            if (! $this->file->topic_id && config('ai.deepseek_api_key')) {
                try {
                    $topicId = $this->classifyAndAssignTopic($text, $deepSeek);
                    $this->file->update(['topic_id' => $topicId]);
                } catch (\Throwable $e) {
                    Log::warning('Topic classification failed, skipping', [
                        'file_id' => $this->file->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 3. Chunk the text
            $chunks = $chunker->chunk($text);

            // 4. Generate embeddings (if server is available)
            $embeddingAvailable = $embedder->isHealthy();

            DB::beginTransaction();

            try {
                if ($embeddingAvailable) {
                    $batchSize = 10;
                    $chunkBatches = array_chunk($chunks, $batchSize);
                    $chunkIndex = 0;

                    foreach ($chunkBatches as $batch) {
                        $embeddings = $embedder->embedBatch($batch);

                        foreach ($batch as $i => $chunkText) {
                            $embedding = $embeddings[$i];
                            $vectorStr = '['.implode(',', $embedding).']';

                            $chunk = DocumentChunk::create([
                                'file_id' => $this->file->id,
                                'topic_id' => $this->file->topic_id,
                                'content' => $chunkText,
                                'chunk_index' => $chunkIndex,
                                'metadata' => [
                                    'source' => $this->file->original_name,
                                    'chunk_of' => count($chunks),
                                ],
                            ]);

                            // Store as text for now; upgrade to pgvector on VPS
                            DB::table('document_chunks')
                                ->where('id', $chunk->id)
                                ->update(['embedding' => $vectorStr]);

                            $chunkIndex++;
                        }
                    }
                } else {
                    // Store chunks without embeddings
                    foreach ($chunks as $chunkIndex => $chunkText) {
                        DocumentChunk::create([
                            'file_id' => $this->file->id,
                            'topic_id' => $this->file->topic_id,
                            'content' => $chunkText,
                            'chunk_index' => $chunkIndex,
                            'metadata' => [
                                'source' => $this->file->original_name,
                                'chunk_of' => count($chunks),
                            ],
                        ]);
                    }

                    Log::info('Embedding server unavailable, stored chunks without embeddings', [
                        'file_id' => $this->file->id,
                    ]);
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            $this->file->update(['status' => 'completed']);

            Log::info('Document processed successfully', [
                'file_id' => $this->file->id,
                'chunks_created' => count($chunks),
                'embeddings' => $embeddingAvailable,
            ]);
        } catch (\Throwable $e) {
            $this->file->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::error('Document processing failed', [
                'file_id' => $this->file->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if the file type cannot be read/extracted by the AI pipeline.
     */
    protected function isUnreadableFile(): bool
    {
        $mime = $this->file->mime_type ?? '';
        $ext = strtolower(pathinfo($this->file->original_name, PATHINFO_EXTENSION));

        // MIME prefixes that are never readable
        $unreadablePrefixes = ['video/', 'audio/', 'font/'];
        foreach ($unreadablePrefixes as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }

        // Specific MIME types that can't be extracted
        $unreadableMimes = [
            'application/zip',
            'application/x-zip-compressed',
            'application/x-rar-compressed',
            'application/vnd.rar',
            'application/x-7z-compressed',
            'application/x-tar',
            'application/gzip',
            'application/x-bzip2',
            'application/java-archive',
            'application/x-iso9660-image',
            'application/x-msdownload',
            'application/x-executable',
            'application/x-sharedlib',
            'application/vnd.android.package-archive',
            'application/x-apple-diskimage',
            'application/octet-stream',
        ];

        if (in_array($mime, $unreadableMimes, true)) {
            return true;
        }

        // File extensions as a fallback
        $unreadableExtensions = [
            'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'iso',
            'mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm', 'm4v',
            'mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a',
            'exe', 'msi', 'dll', 'so', 'dmg', 'apk', 'deb', 'rpm',
            'ttf', 'otf', 'woff', 'woff2',
            'psd', 'ai', 'sketch', 'fig',
            'sql', 'db', 'sqlite', 'mdb',
        ];

        return in_array($ext, $unreadableExtensions, true);
    }

    protected function classifyAndAssignTopic(string $text, DeepSeekService $deepSeek): int
    {
        $existingTopics = Topic::pluck('name')->toArray();

        $textSample = mb_substr($text, 0, 2000);
        $topicName = $deepSeek->classifyTopic($textSample, $existingTopics);

        $topic = Topic::firstOrCreate(
            ['name' => $topicName],
            ['description' => 'Auto-classified from uploaded documents.']
        );

        return $topic->id;
    }
}
