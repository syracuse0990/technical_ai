<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebSocketService
{
    protected string $url;

    protected string $appKey;

    protected string $appSecret;

    public function __construct()
    {
        $this->url = config('websocket.url');
        $this->appKey = config('websocket.app_key');
        $this->appSecret = config('websocket.app_secret');
    }

    /**
     * Trigger an event on one or more channels.
     */
    public function trigger(string|array $channels, string $event, array $data = []): bool
    {
        $channels = is_array($channels) ? $channels : [$channels];

        try {
            $response = Http::withHeaders([
                'X-App-Key' => $this->appKey,
                'X-App-Signature' => $this->appSecret,
            ])->post("{$this->url}/api/trigger", [
                'channels' => $channels,
                'event' => $event,
                'data' => $data,
            ]);

            if ($response->failed()) {
                Log::warning('WebSocket trigger failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'event' => $event,
                    'channels' => $channels,
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('WebSocket trigger error', [
                'error' => $e->getMessage(),
                'event' => $event,
                'channels' => $channels,
            ]);

            return false;
        }
    }

    // ── File Activity Notifications ──────────────────

    /**
     * Notify when a file is uploaded to public.
     */
    public function fileUploaded(array $fileData, string $userName): bool
    {
        return $this->trigger('file-activity', 'file.uploaded', [
            'file' => $fileData,
            'user' => $userName,
            'message' => "{$userName} uploaded \"{$fileData['original_name']}\"",
        ]);
    }

    /**
     * Notify when a file is deleted from public.
     */
    public function fileDeleted(array $fileData, string $userName): bool
    {
        return $this->trigger('file-activity', 'file.deleted', [
            'file' => $fileData,
            'user' => $userName,
            'message' => "{$userName} deleted \"{$fileData['original_name']}\"",
        ]);
    }

    /**
     * Notify when a folder is created in public.
     */
    public function folderCreated(array $folderData, string $userName): bool
    {
        return $this->trigger('file-activity', 'folder.created', [
            'folder' => $folderData,
            'user' => $userName,
            'message' => "{$userName} created folder \"{$folderData['name']}\"",
        ]);
    }

    /**
     * Notify when a folder is deleted from public.
     */
    public function folderDeleted(array $folderData, string $userName): bool
    {
        return $this->trigger('file-activity', 'folder.deleted', [
            'folder' => $folderData,
            'user' => $userName,
            'message' => "{$userName} deleted folder \"{$folderData['name']}\"",
        ]);
    }

    // ── Document Processing ──────────────────────────

    /**
     * Notify file status change (processing complete/failed/skipped).
     */
    public function fileStatusChanged(int $fileId, string $status, string $fileName): bool
    {
        return $this->trigger('file-activity', 'file.status', [
            'file_id' => $fileId,
            'status' => $status,
            'original_name' => $fileName,
        ]);
    }

    // ── Global Toast Notifications ───────────────────

    /**
     * Send a toast notification to all connected clients.
     */
    public function toast(string $type, string $title, ?string $body = null): bool
    {
        return $this->trigger('notifications', 'toast', [
            'type' => $type,
            'title' => $title,
            'body' => $body,
        ]);
    }
}
