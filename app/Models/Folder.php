<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folder extends Model
{
    protected $fillable = [
        'user_id',
        'parent_id',
        'name',
        'visibility',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren', 'files');
    }

    /**
     * Remap 'all_children' to 'children' so the Vue frontend
     * can reference folder.children consistently.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        if (array_key_exists('all_children', $array)) {
            $array['children'] = $array['all_children'];
            unset($array['all_children']);
        }

        return $array;
    }
}
