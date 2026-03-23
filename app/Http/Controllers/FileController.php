<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDocument;
use App\Models\File;
use App\Services\WebSocketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
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
     * Return Excel/CSV file data as JSON for the spreadsheet editor.
     */
    public function spreadsheetData(Request $request, File $file): JsonResponse
    {
        if ($file->visibility !== 'public' && $file->user_id !== $request->user()->id) {
            abort(403);
        }

        $mime = $file->mime_type ?? '';
        $ext = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));

        if (! $this->isSpreadsheetFile($mime, $ext)) {
            return response()->json(['error' => 'Not a spreadsheet file'], 422);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'xls_');

        try {
            $stream = Storage::disk('wasabi')->readStream($file->file_path);
            file_put_contents($tmpFile, $stream);

            $spreadsheet = IOFactory::load($tmpFile);
            $sheets = [];

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $sheetData = $sheet->toArray(null, true, true, false);

                // Determine column count from the widest row
                $colCount = 0;
                foreach ($sheetData as $row) {
                    $colCount = max($colCount, is_array($row) ? count($row) : 0);
                }

                // Build column headers (A, B, C, ...)
                $columns = [];
                for ($i = 0; $i < max($colCount, 1); $i++) {
                    $letter = '';
                    $n = $i;
                    do {
                        $letter = chr(65 + ($n % 26)).$letter;
                        $n = intdiv($n, 26) - 1;
                    } while ($n >= 0);

                    $columns[] = [
                        'title' => $letter,
                        'width' => 120,
                    ];
                }

                // Normalize rows to consistent column count
                $rows = [];
                foreach ($sheetData as $row) {
                    $normalized = array_pad(is_array($row) ? array_values($row) : [], $colCount, '');
                    $rows[] = array_map(fn ($v) => $v === null ? '' : (string) $v, $normalized);
                }

                $sheets[] = [
                    'name' => $sheet->getTitle(),
                    'columns' => $columns,
                    'data' => $rows,
                ];
            }

            $spreadsheet->disconnectWorksheets();

            return response()->json(['sheets' => $sheets]);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Save edited spreadsheet data back to the file.
     */
    public function spreadsheetSave(Request $request, File $file): JsonResponse
    {
        if ($file->user_id !== $request->user()->id) {
            abort(403);
        }

        $request->validate([
            'sheets' => ['required', 'array', 'min:1'],
            'sheets.*.name' => ['required', 'string', 'max:255'],
            'sheets.*.data' => ['required', 'array'],
        ]);

        $ext = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($request->input('sheets') as $i => $sheetInput) {
            $sheet = $spreadsheet->createSheet($i);
            $sheet->setTitle(mb_substr($sheetInput['name'], 0, 31));

            foreach ($sheetInput['data'] as $rowIdx => $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach ($row as $colIdx => $value) {
                    $sheet->setCellValue([$colIdx + 1, $rowIdx + 1], $value ?? '');
                }
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writerType = match ($ext) {
            'csv' => 'Csv',
            'xls' => 'Xls',
            default => 'Xlsx',
        };

        $tmpFile = tempnam(sys_get_temp_dir(), 'xls_save_');

        try {
            $writer = IOFactory::createWriter($spreadsheet, $writerType);
            $writer->save($tmpFile);
            $spreadsheet->disconnectWorksheets();

            Storage::disk('wasabi')->put($file->file_path, file_get_contents($tmpFile));
            $file->update(['file_size' => filesize($tmpFile)]);

            // Re-process document to update chunks
            $file->update(['status' => 'pending']);
            $file->chunks()->delete();
            ProcessDocument::dispatch($file);

            return response()->json(['success' => true]);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Check if a file is a spreadsheet type.
     */
    protected function isSpreadsheetFile(string $mime, string $ext): bool
    {
        return str_contains($mime, 'spreadsheet')
            || str_contains($mime, 'excel')
            || str_contains($mime, 'csv')
            || in_array($ext, ['xlsx', 'xls', 'csv']);
    }

    /**
     * Check if a file is an editable document (text or Word).
     */
    protected function isEditableDocument(string $mime, string $ext): bool
    {
        return $this->isTextFile($mime, $ext) || $this->isWordFile($mime, $ext);
    }

    protected function isTextFile(string $mime, string $ext): bool
    {
        return str_starts_with($mime, 'text/')
            || str_contains($mime, 'json')
            || in_array($ext, ['txt', 'md', 'log', 'xml', 'yaml', 'yml', 'json', 'env', 'ini', 'cfg', 'conf']);
    }

    protected function isWordFile(string $mime, string $ext): bool
    {
        return (str_contains($mime, 'word') || str_contains($mime, 'document'))
            && ! str_contains($mime, 'spreadsheet')
            && in_array($ext, ['docx', 'doc']);
    }

    /**
     * Recursively extract text from a PhpWord element.
     */
    protected function extractWordElementText(mixed $element): string
    {
        // Handle Table elements
        if ($element instanceof Table) {
            $text = '';
            foreach ($element->getRows() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cellText = '';
                    foreach ($cell->getElements() as $cellElement) {
                        $cellText .= $this->extractWordElementText($cellElement);
                    }
                    $cells[] = trim($cellText);
                }
                $text .= implode(' | ', $cells)."\n";
            }

            return $text;
        }

        $text = '';

        if (method_exists($element, 'getText')) {
            $result = $element->getText();
            if (is_string($result)) {
                return $result.' ';
            }
            if (is_object($result)) {
                return $this->extractWordElementText($result);
            }
        }

        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractWordElementText($child);
            }
        }

        return $text;
    }

    /**
     * Return document content for text/Word editing.
     */
    public function documentContent(Request $request, File $file): JsonResponse
    {
        if ($file->visibility !== 'public' && $file->user_id !== $request->user()->id) {
            abort(403);
        }

        $mime = $file->mime_type ?? '';
        $ext = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));

        if (! $this->isEditableDocument($mime, $ext)) {
            return response()->json(['error' => 'Not an editable document'], 422);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'doc_');

        try {
            $stream = Storage::disk('wasabi')->readStream($file->file_path);
            file_put_contents($tmpFile, $stream);

            if ($this->isWordFile($mime, $ext)) {
                $phpWord = WordIOFactory::load($tmpFile);
                $content = '';

                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        $content .= $this->extractWordElementText($element)."\n";
                    }
                }

                return response()->json([
                    'content' => trim($content),
                    'type' => 'word',
                    'extension' => $ext,
                ]);
            }

            // Plain text file
            $content = file_get_contents($tmpFile);

            return response()->json([
                'content' => $content,
                'type' => 'text',
                'extension' => $ext,
            ]);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Save edited document content back to storage.
     */
    public function documentSave(Request $request, File $file): JsonResponse
    {
        if ($file->user_id !== $request->user()->id) {
            abort(403);
        }

        $request->validate([
            'content' => ['required', 'string'],
        ]);

        $mime = $file->mime_type ?? '';
        $ext = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));

        if (! $this->isEditableDocument($mime, $ext)) {
            return response()->json(['error' => 'Not an editable document'], 422);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'doc_save_');

        try {
            $content = $request->input('content');

            if ($this->isWordFile($mime, $ext)) {
                $phpWord = new PhpWord;
                $section = $phpWord->addSection();

                foreach (explode("\n", $content) as $line) {
                    $section->addText(htmlspecialchars($line, ENT_XML1, 'UTF-8'));
                }

                $writer = WordIOFactory::createWriter($phpWord, 'Word2007');
                $writer->save($tmpFile);
            } else {
                file_put_contents($tmpFile, $content);
            }

            Storage::disk('wasabi')->put($file->file_path, file_get_contents($tmpFile));
            $file->update(['file_size' => filesize($tmpFile)]);

            // Re-process document to update chunks
            $file->update(['status' => 'pending']);
            $file->chunks()->delete();
            ProcessDocument::dispatch($file);

            return response()->json(['success' => true]);
        } finally {
            @unlink($tmpFile);
        }
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
