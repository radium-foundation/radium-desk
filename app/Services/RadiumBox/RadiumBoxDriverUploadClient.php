<?php

namespace App\Services\RadiumBox;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class RadiumBoxDriverUploadClient
{
    public function isConfigured(): bool
    {
        $config = config('driver_management.radiumbox_com', []);

        return (bool) ($config['enabled'] ?? false)
            && $this->token() !== ''
            && $this->baseUrl() !== null;
    }

    public function mediaUploadEnabled(): bool
    {
        return $this->isConfigured()
            && filter_var(config('driver_management.radiumbox_com.media_upload_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchCatalog(): array
    {
        $response = $this->request()->get('/api/integrations/v1/driver-upload/catalog');
        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function authorizeMediaUpload(User $actor, int $productDriverId): array
    {
        $response = $this->request()->post('/api/integrations/v1/driver-upload/authorize', $this->actorPayload($actor) + [
            'product_driver_id' => $productDriverId,
        ]);

        if ($response->status() === 422) {
            throw ValidationException::withMessages($response->json('errors') ?? [
                'product_driver_id' => $response->json('message', 'Authorization rejected.'),
            ]);
        }

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function quarantineUpload(User $actor, int $productDriverId, UploadedFile $file): array
    {
        $response = $this->request()
            ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
            ->post('/api/integrations/v1/driver-upload/quarantine', $this->actorPayload($actor) + [
                'product_driver_id' => $productDriverId,
            ]);

        if ($response->status() === 422) {
            throw ValidationException::withMessages($response->json('errors') ?? [
                'file' => $response->json('message', 'Upload rejected.'),
            ]);
        }

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function publish(User $actor, string $uploadId, int $productDriverId, string $idempotencyKey, ?string $sha256 = null): array
    {
        $payload = $this->actorPayload($actor) + [
            'upload_id' => $uploadId,
            'product_driver_id' => $productDriverId,
            'idempotency_key' => $idempotencyKey,
        ];

        if ($sha256 !== null && $sha256 !== '') {
            $payload['sha256'] = $sha256;
        }

        $response = $this->request()->post('/api/integrations/v1/driver-upload/publish', $payload);

        if ($response->status() === 422) {
            throw ValidationException::withMessages($response->json('errors') ?? [
                'upload_id' => $response->json('message', 'Publish rejected.'),
            ]);
        }

        $response->throw();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function actorPayload(User $actor): array
    {
        return [
            'source_system' => 'radium_desk',
            'site_code' => 'radiumbox_com',
            'desk_user_id' => (int) $actor->id,
            'desk_user_email' => (string) $actor->email,
            'desk_user_name' => (string) ($actor->name ?? ''),
        ];
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        if (! $this->isConfigured()) {
            throw ValidationException::withMessages([
                'driver' => 'RadiumBox driver management is not configured.',
            ]);
        }

        $baseUrl = $this->baseUrl();
        if ($baseUrl === null) {
            throw ValidationException::withMessages([
                'driver' => 'RadiumBox base URL is not configured.',
            ]);
        }

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($this->token())
                ->connectTimeout((int) config('driver_management.radiumbox_com.connect_timeout_seconds', 3))
                ->timeout((int) config('driver_management.radiumbox_com.timeout_seconds', 30));

            $host = trim((string) config('driver_management.radiumbox_com.host', ''));
            if ($host !== '') {
                $request = $request->withHeaders(['Host' => $host]);
            }

            return $request;
        } catch (ConnectionException $e) {
            throw ValidationException::withMessages([
                'driver' => 'Unable to reach RadiumBox for driver management.',
            ]);
        }
    }

    private function token(): string
    {
        return trim((string) config('driver_management.radiumbox_com.token', ''));
    }

    private function baseUrl(): ?string
    {
        $url = trim((string) config('driver_management.radiumbox_com.base_url', ''));
        if ($url === '') {
            return null;
        }

        return rtrim($url, '/');
    }
}
