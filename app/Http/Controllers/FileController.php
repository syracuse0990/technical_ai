<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDocument;
use App\Models\File;
use App\Services\WebSocketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /**
     * Upload files to a folder (or root).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:20'],
            'files.*' => ['required', 'file', 'max:51200'],
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
            'visibility' => ['sometimes', 'string', 'in:public,private'],
        ]);

        if ($request->filled('folder_id')) {
            $request->user()->folders()->where('id', $request->folder_id)->firstOrFail();
        }

        $visibility = $request->input('visibility', 'private');
        $uploaded = [];

        foreach ($request->file('files') as $uploadedFile) {
            $filename = uniqid().'_'.$uploadedFile->hashName();
            $path = $uploadedFile->storeAs(
                'files/'.$request->user()->id,
                $filename,
                'wasabi'
            );

            $file = File::create([
                'user_id' => $request->user()->id,
                'folder_id' => $request->folder_id,
                'filename' => $filename,
                'original_name' => $uploadedFile->getClientOriginalName(),
                'mime_type' => $uploadedFile->getMimeType(),
                'file_path' => $path,
                'file_size' => $uploadedFile->getSize(),
                'visibility' => $visibility,
                'status' => 'pending',
            ]);

            ProcessDocument::dispatch($file);

            if ($visibility === 'public') {
                app(WebSocketService::class)->fileUploaded(
                    $file->only('id', 'original_name', 'mime_type', 'file_size', 'folder_id'),
                    $request->user()->name,
                );
            }

            $uploaded[] = $file;
        }

        return response()->json($uploaded, 201);
    }

    /**
     * Download a file.
     */
    public function download(Request $request, File $file): StreamedResponse
    {
        if ($file->visibility !== 'public' && $file->user_id !== $request->user()->id) {
            abort(403);
        }

        return Storage::disk('wasabi')->download(
            $file->file_path,
            $file->original_name
        );
    }

    /**
     * Serve a file inline for preview (PDF, images, etc.).
     */
    public function preview(Request $request, File $file): StreamedResponse
    {
        if ($file->visibility !== 'public' && $file->user_id !== $request->user()->id) {
            abort(403);
        }

        $path = $file->file_path;
        $mime = $file->mime_type ?? 'application/octet-stream';

        return Storage::disk('wasabi')->response($path, $file->original_name, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.addcslashes($file->original_name, '"').'"',
        ]);
    }

    /**
     * Get extracted text content for a file.
     */
    public function content(Request $request, File $file): JsonResponse
    {
        if ($file->visibility !== 'public' && $file->user_id !== $request->user()->id) {
            abort(403);
        }

        $chunks = $file->chunks()->orderBy('chunk_index')->pluck('content');

        return response()->json([
            'text' => $chunks->implode("\n\n"),
            'chunks_count' => $chunks->count(),
        ]);
    }

    /**
     * Move a file to a different folder.
     */
    public function move(Request $request, File $file): JsonResponse
    {
        if ($file->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
        ]);

        if (isset($validated['folder_id'])) {
            $request->user()->folders()->where('id', $validated['folder_id'])->firstOrFail();
        }

        $file->update(['folder_id' => $validated['folder_id'] ?? null]);

        return response()->json($file);
    }

    /**
     * Rename a file.
     */
    public function rename(Request $request, File $file): JsonResponse
    {
        if ($file->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'original_name' => ['required', 'string', 'max:255'],
        ]);

        $file->update($validated);

        return response()->json($file);
    }

    /**
     * Delete a file.
     */
    public function destroy(Request $request, File $file): JsonResponse
    {
        if ($file->user_id !== $request->user()->id) {
            abort(403);
        }

        $fileData = $file->only('id', 'original_name', 'mime_type', 'file_size', 'folder_id', 'visibility');
        $userName = $request->user()->name;

        Storage::disk('wasabi')->delete($file->file_path);
        $file->chunks()->delete();
        $file->delete();

        if ($fileData['visibility'] === 'public') {
            app(WebSocketService::class)->fileDeleted($fileData, $userName);
        }

        return response()->json(['message' => 'File deleted.']);
    }

    /**
     * Get files in root (no folder) for current user.
     */
    public function rootFiles(Request $request): JsonResponse
    {
        $visibility = $request->query('visibility', 'private');

        if ($visibility === 'public') {
            $files = File::query()
                ->where('visibility', 'public')
                ->whereNull('folder_id')
                ->orderBy('original_name')
                ->get();
        } else {
            $files = File::query()
                ->where('user_id', $request->user()->id)
                ->where('visibility', 'private')
                ->whereNull('folder_id')
                ->orderBy('original_name')
                ->get();
        }

        return response()->json($files);
    }
}
