<?php

namespace App\Services;

class ChunkingService
{
    protected int $chunkSize;

    protected int $chunkOverlap;

    public function __construct()
    {
        $this->chunkSize = config('ai.chunk_size', 250);
        $this->chunkOverlap = config('ai.chunk_overlap', 40);
    }

    protected function cleanText(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = preg_replace('/([a-z])([A-Z])/', '$1 $2', $text);
        $text = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $text);
        $text = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $text);
        $text = preg_replace('/[^\S\n]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Split text into overlapping chunks, respecting paragraph and sentence boundaries.
     *
     * @return string[]
     */
    public function chunk(string $text): array
    {
        $text = $this->cleanText($text);

        if (preg_match('/^## Slide \d+/m', $text)) {
            return $this->chunkSlides($text);
        }

        return $this->chunkText($text);
    }

    /**
     * @return string[]
     */
    protected function chunkSlides(string $text): array
    {
        $slides = preg_split('/(?=^## Slide \d+)/m', $text, -1, PREG_SPLIT_NO_EMPTY);
        $slides = array_map('trim', $slides);
        $slides = array_filter($slides, fn ($s) => mb_strlen($s) > 10);

        $chunks = [];
        $currentChunk = '';
        $currentWordCount = 0;

        foreach ($slides as $slide) {
            $slideWords = str_word_count($slide);

            if ($slideWords > $this->chunkSize) {
                if ($currentWordCount > 0) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                    $currentWordCount = 0;
                }
                $chunks = array_merge($chunks, $this->chunkText($slide));

                continue;
            }

            if ($currentWordCount + $slideWords > $this->chunkSize && $currentWordCount > 0) {
                $chunks[] = trim($currentChunk);
                $currentChunk = $slide;
                $currentWordCount = $slideWords;
            } else {
                $currentChunk .= ($currentWordCount > 0 ? "\n\n" : '').$slide;
                $currentWordCount += $slideWords;
            }
        }

        if ($currentWordCount > 0) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * @return string[]
     */
    protected function chunkText(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $paragraphs = array_map('trim', $paragraphs);
        $paragraphs = array_filter($paragraphs, fn ($p) => mb_strlen($p) > 10);

        if (empty($paragraphs)) {
            $text = preg_replace('/\s+/', ' ', trim($text));

            return mb_strlen($text) > 10 ? [$text] : [];
        }

        $chunks = [];
        $currentChunk = '';
        $currentWordCount = 0;

        foreach ($paragraphs as $para) {
            $paraWords = str_word_count($para);

            if ($paraWords > $this->chunkSize) {
                if ($currentWordCount > 0) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                    $currentWordCount = 0;
                }

                $sentenceChunks = $this->splitBySentences($para);
                array_push($chunks, ...$sentenceChunks);

                continue;
            }

            if ($currentWordCount + $paraWords > $this->chunkSize && $currentWordCount > 0) {
                $chunks[] = trim($currentChunk);

                $overlapText = $this->getOverlapText($currentChunk);
                $currentChunk = $overlapText ? $overlapText."\n\n".$para : $para;
                $currentWordCount = str_word_count($currentChunk);
            } else {
                $currentChunk .= ($currentWordCount > 0 ? "\n\n" : '').$para;
                $currentWordCount += $paraWords;
            }
        }

        if ($currentWordCount > 0) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * @return string[]
     */
    protected function splitBySentences(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $chunks = [];
        $currentChunk = '';
        $currentWordCount = 0;

        foreach ($sentences as $sentence) {
            $sentenceWords = str_word_count($sentence);

            if ($currentWordCount + $sentenceWords > $this->chunkSize && $currentWordCount > 0) {
                $chunks[] = trim($currentChunk);
                $overlapText = $this->getOverlapText($currentChunk);
                $currentChunk = $overlapText ? $overlapText.' '.$sentence : $sentence;
                $currentWordCount = str_word_count($currentChunk);
            } else {
                $currentChunk .= ($currentWordCount > 0 ? ' ' : '').$sentence;
                $currentWordCount += $sentenceWords;
            }
        }

        if ($currentWordCount > 0) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    protected function getOverlapText(string $text): string
    {
        $words = explode(' ', $text);
        if (count($words) <= $this->chunkOverlap) {
            return '';
        }

        return implode(' ', array_slice($words, -$this->chunkOverlap));
    }
}
