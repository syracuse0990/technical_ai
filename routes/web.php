<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Folders
    Route::get('/api/folders/tree', [FolderController::class, 'tree'])->name('folders.tree');
    Route::post('/api/folders', [FolderController::class, 'store'])->name('folders.store');
    Route::put('/api/folders/{folder}', [FolderController::class, 'update'])->name('folders.update');
    Route::delete('/api/folders/{folder}', [FolderController::class, 'destroy'])->name('folders.destroy');

    // Files
    Route::get('/api/files/root', [FileController::class, 'rootFiles'])->name('files.root');
    Route::post('/api/files', [FileController::class, 'store'])->name('files.store');
    Route::get('/api/files/{file}/download', [FileController::class, 'download'])->name('files.download');
    Route::get('/api/files/{file}/preview', [FileController::class, 'preview'])->name('files.preview');
    Route::get('/api/files/{file}/content', [FileController::class, 'content'])->name('files.content');
    Route::patch('/api/files/{file}/move', [FileController::class, 'move'])->name('files.move');
    Route::patch('/api/files/{file}/rename', [FileController::class, 'rename'])->name('files.rename');
    Route::delete('/api/files/{file}', [FileController::class, 'destroy'])->name('files.destroy');

    // Chat
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('/chat', [ChatController::class, 'startConversation'])->name('chat.start');
    Route::get('/chat/{conversation}', [ChatController::class, 'show'])->name('chat.show');
    Route::post('/chat/{conversation}/message', [ChatController::class, 'sendMessage'])->name('chat.message');
    Route::post('/chat/{conversation}/stream', [ChatController::class, 'stream'])->name('chat.stream');
    Route::delete('/chat/{conversation}', [ChatController::class, 'destroyConversation'])->name('chat.destroy');
    Route::post('/chat/{conversation}/system-prompt', [ChatController::class, 'updateSystemPrompt'])->name('chat.systemPrompt');
    Route::post('/messages/{message}/feedback', [ChatController::class, 'feedback'])->name('messages.feedback');
});

require __DIR__.'/auth.php';
