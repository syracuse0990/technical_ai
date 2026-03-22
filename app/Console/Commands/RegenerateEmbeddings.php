<?php

namespace App\Console\Commands;

use App\Models\DocumentChunk;
use App\Services\EmbeddingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('embeddings:regenerate {--force : Regenerate all embeddings, even existing ones}')]
#[Description('Generate embeddings for document chunks that are missing them')]
class RegenerateEmbeddings extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(EmbeddingService $embedder): int
    {
        if (! $embedder->isHealthy()) {
            $this->error('Embedding server is not running. Start it first: cd embedding-server && python server.py');

            return self::FAILURE;
        }

        $query = DocumentChunk::query();
        if (! $this->option('force')) {
            $query->whereNull('embedding');
        }

        $chunks = $query->get();

        if ($chunks->isEmpty()) {
            $this->info('No chunks need embedding.');

            return self::SUCCESS;
        }

        $this->info("Processing {$chunks->count()} chunks...");
        $bar = $this->output->createProgressBar($chunks->count());

        $success = 0;
        $failed = 0;

        foreach ($chunks as $chunk) {
            try {
                $embedding = $embedder->embed($chunk->content);
                $embeddingJson = json_encode($embedding);

                DB::table('document_chunks')
                    ->where('id', $chunk->id)
                    ->update(['embedding' => $embeddingJson]);

                $success++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->warn("Failed chunk #{$chunk->id}: {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. {$success} embedded, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
