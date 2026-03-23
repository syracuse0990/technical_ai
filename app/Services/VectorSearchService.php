<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VectorSearchService
{
    protected int $retrieveK;

    protected int $finalK;

    protected float $threshold;

    public function __construct(protected EmbeddingService $embeddingService)
    {
        $this->retrieveK = config('ai.search_retrieve_k', 20);
        $this->finalK = config('ai.search_top_k', 12);
        $this->threshold = config('ai.similarity_threshold', 0.85);
    }

    /**
     * Advanced RAG search: multi-query expansion → hybrid retrieval → neighbor expansion → AI reranking.
     *
     * @return array<int, array{content: string, distance: float, file_id: int, chunk_index: int, source: string}>
     */
    public function search(string $query, ?int $topicId = null, ?int $userId = null): array
    {
        $englishQuery = $this->translateForSearch($query);
        $searchQuery = $englishQuery ?: $query;

        $keywords = $this->extractKeywords($query);
        if ($englishQuery && $englishQuery !== $query) {
            $keywords = array_unique(array_merge($keywords, $this->extractKeywords($englishQuery)));
        }

        // Step 1: Multi-query expansion — generate alternative search angles
        $searchQueries = $this->expandQueries($searchQuery);

        // Step 2: Retrieve candidates from all query variants
        $allResults = [];
        $seenKeys = [];

        foreach ($searchQueries as $sq) {
            try {
                $embedding = $this->embeddingService->embed($sq);
                $results = $this->hybridSearch($embedding, $keywords, $topicId, $userId);
                foreach ($results as $r) {
                    $key = $r['file_id'].'-'.$r['chunk_index'];
                    if (! isset($seenKeys[$key])) {
                        $allResults[] = $r;
                        $seenKeys[$key] = true;
                    }
                }
            } catch (\Exception $e) {
                Log::debug('Search query variant failed', ['query' => $sq, 'error' => $e->getMessage()]);
            }
        }

        // Step 3: Keyword fallback for all chunks (catches filename matches + non-embedded chunks)
        if (! empty($keywords)) {
            $keywordResults = $this->keywordFallbackSearch($keywords, $topicId, $userId);
            foreach ($keywordResults as $kr) {
                $key = $kr['file_id'].'-'.$kr['chunk_index'];
                if (! isset($seenKeys[$key])) {
                    $allResults[] = $kr;
                    $seenKeys[$key] = true;
                }
            }
        }

        if (empty($allResults)) {
            Log::info('All search strategies returned empty', ['query' => $query, 'keywords' => $keywords]);

            return [];
        }

        // Step 4: Expand with neighboring chunks for context continuity
        $allResults = $this->expandWithNeighbors($allResults, $userId);

        // Step 5: AI reranking — use DeepSeek to score relevance
        $reranked = $this->rerankWithAI($query, $allResults);

        Log::info('Search pipeline complete', [
            'query' => $query,
            'candidates' => count($allResults),
            'after_rerank' => count($reranked),
        ]);

        return $reranked;
    }

    /**
     * Generate alternative search queries to catch different phrasings.
     *
     * @return string[]
     */
    protected function expandQueries(string $query): array
    {
        $queries = [$query];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('ai.deepseek_api_key'),
                'Content-Type' => 'application/json',
            ])->timeout(15)->post(config('ai.deepseek_base_url').'/chat/completions', [
                'model' => config('ai.deepseek_model'),
                'messages' => [
                    ['role' => 'system', 'content' => 'Generate 3 alternative search queries for a document retrieval system. Each should approach the topic from a different angle or use different keywords. Return ONLY the 3 queries, one per line, no numbering or bullets.'],
                    ['role' => 'user', 'content' => $query],
                ],
                'temperature' => 0.7,
                'max_tokens' => 200,
            ]);

            if ($response->successful()) {
                $lines = array_filter(
                    array_map('trim', explode("\n", $response->json('choices.0.message.content', ''))),
                    fn ($l) => mb_strlen($l) > 5
                );
                $queries = array_merge($queries, array_slice($lines, 0, 3));
            }
        } catch (\Exception $e) {
            Log::debug('Query expansion failed, using original only', ['error' => $e->getMessage()]);
        }

        return array_unique($queries);
    }

    /**
     * Expand results with neighboring chunks (chunk_index ± 1) for context continuity.
     *
     * @param  array<int, array{content: string, distance: float, file_id: int, chunk_index: int, source: string}>  $results
     * @return array<int, array{content: string, distance: float, file_id: int, chunk_index: int, source: string}>
     */
    protected function expandWithNeighbors(array $results, ?int $userId = null): array
    {
        if (empty($results)) {
            return [];
        }

        $neighborPairs = [];
        $existingKeys = [];
        foreach ($results as $r) {
            $existingKeys[$r['file_id'].'-'.$r['chunk_index']] = true;
            $neighborPairs[] = ['file_id' => $r['file_id'], 'chunk_index' => $r['chunk_index'] - 1];
            $neighborPairs[] = ['file_id' => $r['file_id'], 'chunk_index' => $r['chunk_index'] + 1];
        }

        // Filter out neighbors we already have
        $neighborPairs = array_filter(
            $neighborPairs,
            fn ($p) => $p['chunk_index'] >= 0 && ! isset($existingKeys[$p['file_id'].'-'.$p['chunk_index']])
        );

        if (empty($neighborPairs)) {
            return $results;
        }

        // Build query for neighbors
        $whereClauses = [];
        $bindings = [];
        foreach ($neighborPairs as $pair) {
            $whereClauses[] = '(dc.file_id = ? AND dc.chunk_index = ?)';
            $bindings[] = $pair['file_id'];
            $bindings[] = $pair['chunk_index'];
        }

        $sql = '
            SELECT dc.id, dc.file_id, dc.content, dc.chunk_index, dc.metadata,
                   f.original_name AS source_name
            FROM document_chunks dc
            LEFT JOIN files f ON f.id = dc.file_id
            WHERE ('.implode(' OR ', $whereClauses).')
        ';

        if ($userId) {
            $sql .= ' AND (f.user_id = ? OR f.visibility = ?)';
            $bindings[] = $userId;
            $bindings[] = 'public';
        }

        $neighbors = DB::select($sql, $bindings);

        foreach ($neighbors as $n) {
            $key = $n->file_id.'-'.$n->chunk_index;
            if (! isset($existingKeys[$key])) {
                $results[] = [
                    'content' => $n->content,
                    'distance' => 0.9,
                    'file_id' => $n->file_id,
                    'chunk_index' => $n->chunk_index,
                    'source' => $n->source_name ?? 'Unknown',
                ];
                $existingKeys[$key] = true;
            }
        }

        return $results;
    }

    /**
     * Use DeepSeek to rerank candidates by relevance to the query.
     *
     * @param  array<int, array{content: string, distance: float, file_id: int, chunk_index: int, source: string}>  $candidates
     * @return array<int, array{content: string, distance: float, file_id: int, chunk_index: int, source: string}>
     */
    protected function rerankWithAI(string $query, array $candidates): array
    {
        if (count($candidates) <= $this->finalK) {
            return $candidates;
        }

        // Prepare numbered passages for the reranker
        $passages = [];
        foreach ($candidates as $i => $c) {
            $preview = mb_substr($c['content'], 0, 300);
            $passages[] = "[{$i}] (Source: {$c['source']}) {$preview}";
        }

        $passageText = implode("\n\n", $passages);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('ai.deepseek_api_key'),
                'Content-Type' => 'application/json',
            ])->timeout(20)->post(config('ai.deepseek_base_url').'/chat/completions', [
                'model' => config('ai.deepseek_model'),
                'messages' => [
                    ['role' => 'system', 'content' => "You are a relevance scoring assistant. Given a user query and numbered document passages, return the indices of the {$this->finalK} most relevant passages in order of relevance. Return ONLY the indices as comma-separated numbers (e.g., 3,0,7,2,5,1,4,9,6,8). No explanations."],
                    ['role' => 'user', 'content' => "Query: {$query}\n\nPassages:\n{$passageText}"],
                ],
                'temperature' => 0.0,
                'max_tokens' => 100,
            ]);

            if ($response->successful()) {
                $content = trim($response->json('choices.0.message.content', ''));
                // Extract numbers from response
                preg_match_all('/\d+/', $content, $matches);
                $indices = array_map('intval', $matches[0] ?? []);
                $indices = array_filter($indices, fn ($i) => $i >= 0 && $i < count($candidates));
                $indices = array_unique($indices);

                if (count($indices) >= 3) {
                    $reranked = [];
                    foreach (array_slice($indices, 0, $this->finalK) as $idx) {
                        $reranked[] = $candidates[$idx];
                    }

                    return $reranked;
                }
            }
        } catch (\Exception $e) {
            Log::debug('AI reranking failed, using distance-sorted fallback', ['error' => $e->getMessage()]);
        }

        // Fallback: sort by distance and take top finalK
        usort($candidates, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return array_slice($candidates, 0, $this->finalK);
    }

    protected function translateForSearch(string $query): ?string
    {
        if (preg_match('/^[a-zA-Z0-9\s\?\.\,\!\-\'\"]+$/', $query)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('ai.deepseek_api_key'),
                'Content-Type' => 'application/json',
            ])->timeout(10)->post(config('ai.deepseek_base_url').'/chat/completions', [
                'model' => config('ai.deepseek_model'),
                'messages' => [
                    ['role' => 'system', 'content' => 'Translate the user\'s message to English. Return ONLY the English translation, nothing else. If it\'s already English, return it as-is.'],
                    ['role' => 'user', 'content' => $query],
                ],
                'temperature' => 0.1,
                'max_tokens' => 100,
            ]);

            if ($response->successful()) {
                $translated = trim($response->json('choices.0.message.content', ''));
                if ($translated && mb_strlen($translated) > 2) {
                    return $translated;
                }
            }
        } catch (\Exception $e) {
            Log::debug('Query translation failed, using original', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * @return string[]
     */
    protected function extractKeywords(string $query): array
    {
        $stopWords = ['the', 'is', 'are', 'was', 'were', 'who', 'what', 'when', 'where', 'why', 'how',
            'can', 'could', 'would', 'should', 'does', 'did', 'has', 'have', 'had', 'will',
            'and', 'but', 'for', 'not', 'this', 'that', 'with', 'from', 'about', 'into',
            'than', 'then', 'also', 'just', 'more', 'some', 'any', 'all', 'its',
            'tell', 'give', 'know', 'show', 'find',
            'ang', 'mga', 'lang', 'din', 'rin', 'naman', 'kasi', 'pero', 'para', 'kung',
            'ano', 'saan', 'kailan', 'bakit', 'paano', 'nang', 'yung', 'dito', 'doon'];

        $words = preg_split('/\s+/', mb_strtolower(trim($query)));
        $words = array_map(fn ($w) => preg_replace('/[^\w]/', '', $w), $words);

        return array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 2 && ! in_array($w, $stopWords)));
    }

    /**
     * @return array<int, array{content: string, distance: float, file_id: int, chunk_index: int, source: string}>
     */
    protected function hybridSearch(array $queryEmbedding, array $keywords, ?int $topicId = null, ?int $userId = null): array
    {
        $vectorParam = '['.implode(',', $queryEmbedding).']';

        $keywordBoost = '0';
        $bindings = [];
        $bindings[] = $vectorParam;

        if (! empty($keywords)) {
            $boostParts = [];
            foreach ($keywords as $kw) {
                $boostParts[] = 'CASE WHEN LOWER(dc.content) LIKE ? THEN 0.3 ELSE 0 END';
                $bindings[] = '%'.str_replace(['%', '_'], ['\%', '\_'], $kw).'%';
            }
            foreach ($keywords as $kw) {
                $boostParts[] = 'CASE WHEN LOWER(f.original_name) LIKE ? THEN 0.35 ELSE 0 END';
                $bindings[] = '%'.str_replace(['%', '_'], ['\%', '\_'], $kw).'%';
            }
            $keywordBoost = implode(' + ', $boostParts);
        }

        $sql = "
            SELECT *, (vector_dist - keyword_boost) AS combined_score FROM (
                SELECT dc.id, dc.file_id, dc.content, dc.chunk_index, dc.metadata,
                       f.original_name AS source_name,
                       (dc.embedding <=> ?::vector) AS vector_dist,
                       ({$keywordBoost}) AS keyword_boost
                FROM document_chunks dc
                LEFT JOIN files f ON f.id = dc.file_id
                WHERE dc.embedding IS NOT NULL
        ";

        if ($userId) {
            $sql .= ' AND (f.user_id = ? OR f.visibility = ?)';
            $bindings[] = $userId;
            $bindings[] = 'public';
        }

        if ($topicId) {
            $sql .= ' AND dc.topic_id = ?';
            $bindings[] = $topicId;
        }

        $sql .= '
            ) sub
            WHERE (vector_dist - keyword_boost) < ?
            ORDER BY combined_score ASC
            LIMIT ?
        ';
        $bindings[] = $this->threshold;
        $bindings[] = $this->retrieveK;

        $results = DB::select($sql, $bindings);

        return array_map(fn ($chunk) => [
            'content' => $chunk->content,
            'distance' => $chunk->combined_score,
            'file_id' => $chunk->file_id,
            'chunk_index' => $chunk->chunk_index,
            'source' => $chunk->source_name ?? 'Unknown',
        ], $results);
    }

    /**
     * @return array<int, array{content: string, distance: float, file_id: int, chunk_index: int, source: string}>
     */
    protected function keywordFallbackSearch(array $keywords, ?int $topicId = null, ?int $userId = null): array
    {
        if (empty($keywords)) {
            return [];
        }

        $bindings = [];
        $contentParts = [];
        $fileNameParts = [];

        foreach ($keywords as $kw) {
            $escaped = str_replace(['%', '_'], ['\%', '\_'], $kw);
            $contentParts[] = 'CASE WHEN LOWER(dc.content) LIKE ? THEN 1 ELSE 0 END';
            $bindings[] = '%'.$escaped.'%';
        }

        foreach ($keywords as $kw) {
            $escaped = str_replace(['%', '_'], ['\%', '\_'], $kw);
            $fileNameParts[] = 'CASE WHEN LOWER(f.original_name) LIKE ? THEN 1 ELSE 0 END';
            $bindings[] = '%'.$escaped.'%';
        }

        // Score: filename matches weighted higher (0.2) vs content matches (0.4)
        // Lower score = better match (consistent with vector distance)
        $contentScore = implode(' + ', $contentParts);
        $fileNameScore = implode(' + ', $fileNameParts);
        $kwCount = count($keywords);

        // Build WHERE clause: content OR filename match
        $whereBindings = [];
        $whereParts = [];
        foreach ($keywords as $kw) {
            $escaped = str_replace(['%', '_'], ['\%', '\_'], $kw);
            $whereParts[] = 'LOWER(dc.content) LIKE ?';
            $whereBindings[] = '%'.$escaped.'%';
        }
        foreach ($keywords as $kw) {
            $escaped = str_replace(['%', '_'], ['\%', '\_'], $kw);
            $whereParts[] = 'LOWER(f.original_name) LIKE ?';
            $whereBindings[] = '%'.$escaped.'%';
        }

        // Merge all bindings: score bindings first, then WHERE bindings
        $bindings = array_merge($bindings, $whereBindings);

        $sql = "
            SELECT dc.id, dc.file_id, dc.content, dc.chunk_index, dc.metadata,
                   f.original_name AS source_name,
                   (0.5 - (0.1 * ({$contentScore}) / {$kwCount}) - (0.2 * ({$fileNameScore}) / {$kwCount})) AS combined_score
            FROM document_chunks dc
            LEFT JOIN files f ON f.id = dc.file_id
            WHERE (".implode(' OR ', $whereParts).')
        ';

        if ($userId) {
            $sql .= ' AND (f.user_id = ? OR f.visibility = ?)';
            $bindings[] = $userId;
            $bindings[] = 'public';
        }

        if ($topicId) {
            $sql .= ' AND dc.topic_id = ?';
            $bindings[] = $topicId;
        }

        $sql .= ' ORDER BY combined_score ASC LIMIT ?';
        $bindings[] = $this->retrieveK;

        $results = DB::select($sql, $bindings);

        return array_map(fn ($chunk) => [
            'content' => $chunk->content,
            'distance' => $chunk->combined_score,
            'file_id' => $chunk->file_id,
            'chunk_index' => $chunk->chunk_index,
            'source' => $chunk->source_name ?? 'Unknown',
        ], $results);
    }
}
