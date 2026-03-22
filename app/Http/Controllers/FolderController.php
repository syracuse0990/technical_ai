<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Services\WebSocketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    /**
     * Get the full folder tree for the authenticated user.
     */
    public function tree(Request $request): JsonResponse
    {
        $visibility = $request->query('visibility', 'private');

        if ($visibility === 'public') {
            // Public mode: show all public root folders
            $folders = Folder::query()
                ->where('visibility', 'public')
                ->whereNull('parent_id')
                ->with('allChildren', 'files')
                ->orderBy('name')
                ->get();
        } else {
            // Private mode: show only the user's own root folders
            $folders = Folder::query()
                ->where('user_id', $request->user()->id)
                ->where('visibility', 'private')
                ->whereNull('parent_id')
                ->with('allChildren', 'files')
                ->orderBy('name')
                ->get();
        }

        return response()->json($folders);
    }

    /**
     * Create a new folder.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:folders,id'],
            'visibility' => ['sometimes', 'string', 'in:public,private'],
        ]);

        if (isset($validated['parent_id'])) {
            $parent = Folder::where('id', $validated['parent_id'])
                ->where('user_id', $request->user()->id)
                ->firstOrFail();

            $depth = $this->getFolderDepth($parent);
            if ($depth >= 5) {
                return response()->json(['message' => 'Maximum folder depth (5 levels) reached.'], 422);
            }
        }

        $folder = Folder::create([
            'user_id' => $request->user()->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => $validated['name'],
            'visibility' => $validated['visibility'] ?? 'private',
        ]);

        $folder->load('allChildren', 'files');

        if (($validated['visibility'] ?? 'private') === 'public') {
            app(WebSocketService::class)->folderCreated(
                $folder->only('id', 'name', 'parent_id'),
                $request->user()->name,
            );
        }

        return response()->json($folder, 201);
    }

    /**
     * Update a folder (rename or move).
     */
    public function update(Request $request, Folder $folder): JsonResponse
    {
        if ($folder->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:folders,id'],
        ]);

        if (array_key_exists('parent_id', $validated) && $validated['parent_id'] !== $folder->parent_id) {
            if ($validated['parent_id'] === $folder->id) {
                return response()->json(['message' => 'Cannot move a folder into itself.'], 422);
            }

            if ($validated['parent_id'] !== null) {
                $target = Folder::where('id', $validated['parent_id'])
                    ->where('user_id', $request->user()->id)
                    ->firstOrFail();

                $depth = $this->getFolderDepth($target);
                if ($depth >= 5) {
                    return response()->json(['message' => 'Maximum folder depth (5 levels) reached.'], 422);
                }
            }

            $folder->parent_id = $validated['parent_id'];
        }

        $folder->name = $validated['name'];
        $folder->save();

        return response()->json($folder);
    }

    /**
     * Delete a folder and all its contents.
     */
    public function destroy(Request $request, Folder $folder): JsonResponse
    {
        if ($folder->user_id !== $request->user()->id) {
            abort(403);
        }

        $folderData = $folder->only('id', 'name', 'parent_id', 'visibility');
        $userName = $request->user()->name;

        $folder->delete();

        if ($folderData['visibility'] === 'public') {
            app(WebSocketService::class)->folderDeleted($folderData, $userName);
        }

        return response()->json(['message' => 'Folder deleted.']);
    }

    protected function getFolderDepth(Folder $folder): int
    {
        $depth = 1;
        $current = $folder;
        while ($current->parent_id) {
            $current = $current->parent;
            $depth++;
        }

        return $depth;
    }
}
