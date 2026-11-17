<?php

namespace App\Services\HardwareFulfilment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class HardwareCarrierDocumentFetcher
{
    public function __construct(
        private readonly CarrierDocumentUrlValidator $urlValidator,
        private readonly int $maxBytes,
        private readonly int $timeoutSeconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            CarrierDocumentUrlValidator::fromConfig(),
            (int) config('shipping.document_download_max_bytes', 15 * 1024 * 1024),
            (int) config('shipping.document_download_timeout_seconds', 20),
        );
    }

    /**
     * @return array{bytes: string, content_type: string|null}|null
     */
    public function fetchRemotePdf(string $url): ?array
    {
        if (! $this->urlValidator->isAllowedUrl($url)) {
            return null;
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->connectTimeout(min(5, $this->timeoutSeconds))
            ->withOptions(['allow_redirects' => ['max' => 3, 'strict' => true, 'referer' => false, 'protocols' => ['https']]])
            ->get($url);

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        if ($body === '' || strlen($body) > $this->maxBytes) {
            return null;
        }

        return [
            'bytes' => $body,
            'content_type' => $response->header('Content-Type'),
        ];
    }

    /**
     * @return array{bytes: string, filename: string}|null
     */
    public function readLocalDisk(string $disk, string $path): ?array
    {
        if ($disk !== 'local' && $disk !== 'public') {
            return null;
        }

        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $size = Storage::disk($disk)->size($path);
        if ($size <= 0 || $size > $this->maxBytes) {
            return null;
        }

        return [
            'bytes' => Storage::disk($disk)->get($path),
            'filename' => basename($path),
        ];
    }
}
