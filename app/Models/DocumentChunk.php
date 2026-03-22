<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    protected $fillable = [
        'file_id',
        'topic_id',
        'content',
        'chunk_index',
        'metadata',
    ];

    /**
     * @return array{content: 'string', metadata: 'array'}
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }
}
