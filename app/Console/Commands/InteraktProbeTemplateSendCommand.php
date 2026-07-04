<?php

namespace App\Console\Commands;

use App\Services\Interakt\InteraktAuthentication;
use App\Services\Interakt\InteraktService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Temporary diagnostic command for P04-07-006. Safe to delete after investigation.
 */
#[Signature('interakt:probe-template-send
    {--phone=9876543210 : Local phone number without country code}
    {--country=+91 : Country code}
    {--api-key= : Override INTERAKT_API_KEY for this probe only}
    {--dry-run : Build and print payload without calling Interakt}')]
#[Description('P04-07-006: Probe Interakt body-only template send and capture HTTP exchange')]
class InteraktProbeTemplateSendCommand extends Command
{
    /** @var array<string, mixed>|null */
    private ?array $capturedRequest = null;

    /** @var array<string, mixed>|null */
    private ?array $capturedResponse = null;

    public function handle(InteraktService $interaktService, InteraktAuthentication $authentication): int
    {
        if ($apiKey = $this->option('api-key')) {
            config(['interakt.api_key' => $apiKey]);
        }

        $template = [
            'name' => 'support_booking_confirmation',
            'languageCode' => 'en',
            'bodyValues' => [
                'Test User',
                'RDTEST001',
                '05 Jul 2026',
                'Morning (9 AM – 12 PM)',
            ],
        ];

        $countryCode = (string) $this->option('country');
        $phoneNumber = (string) $this->option('phone');

        $expectedBody = [
            'countryCode' => $countryCode,
            'phoneNumber' => $phoneNumber,
            'type' => 'Template',
            'template' => $template,
            'callbackData' => 'p04-07-006:probe',
        ];

        $this->info('=== P04-07-006 Interakt template probe ===');
        $this->newLine();
        $this->line('Official docs reference (body-only template, headerValues omitted):');
        $this->line('https://www.interakt.shop/resource-center/how-to-send-whatsapp-templates-using-apis-webhooks/');
        $this->newLine();

        $this->comment('Expected request (matches InteraktService::sendTemplateMessage shape):');
        $this->line(json_encode($expectedBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->newLine();

        $this->comment('Documentation parity check:');
        $this->line('- headerValues present: '.(array_key_exists('headerValues', $template) ? 'YES (unexpected)' : 'NO (correct for body-only)'));
        $this->line('- type: Template');
        $this->line('- template.name: support_booking_confirmation');
        $this->line('- template.languageCode: en');
        $this->line('- template.bodyValues count: '.count($template['bodyValues']));
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no HTTP request sent.');

            if (! filled(config('interakt.api_key'))) {
                $this->warn('INTERAKT_API_KEY is not configured. Pass --api-key=... for a live probe.');
            }

            return self::SUCCESS;
        }

        if (! filled(config('interakt.api_key'))) {
            $this->error('INTERAKT_API_KEY is not configured. Pass --api-key=... or set INTERAKT_API_KEY in .env');

            return self::FAILURE;
        }

        $this->registerHttpCapture($authentication);

        $result = $interaktService->sendTemplateMessage(
            countryCode: $countryCode,
            phoneNumber: $phoneNumber,
            template: $template,
            callbackData: 'p04-07-006:probe',
        );

        $this->newLine();
        $this->comment('Captured HTTP request:');
        $this->line(json_encode($this->redactRequest($this->capturedRequest ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->newLine();

        $this->comment('HTTP status:');
        $this->line((string) ($this->capturedResponse['status'] ?? 'unknown'));
        $this->newLine();

        $this->comment('Full HTTP response body:');
        $this->line(json_encode($this->capturedResponse['body'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->newLine();

        $this->comment('InteraktSendResult:');
        $this->line(json_encode([
            'success' => $result->success,
            'messageId' => $result->messageId,
            'errorMessage' => $result->errorMessage,
            'httpStatus' => $result->httpStatus,
            'retriable' => $result->retriable,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->newLine();

        $errorMessage = is_string($result->errorMessage) ? $result->errorMessage : '';
        if (str_contains($errorMessage, "Missing variable values for template's header")) {
            $this->error('CONCLUSION: Interakt rejected a valid body-only payload. Issue is external to application code.');
        } elseif ($result->success) {
            $this->info('CONCLUSION: Interakt accepted the body-only template request.');
        } else {
            $this->warn('CONCLUSION: Interakt returned an error (see response above). Review whether it is payload-related or account/template-related.');
        }

        return $result->success ? self::SUCCESS : self::FAILURE;
    }

    private function registerHttpCapture(InteraktAuthentication $authentication): void
    {
        Http::beforeSending(function (\Illuminate\Http\Client\Request $request) use ($authentication): void {
            $headers = collect($request->headers())->mapWithKeys(function ($value, $key) {
                return [$key => is_array($value) ? ($value[0] ?? '') : $value];
            })->all();

            $this->capturedRequest = [
                'url' => (string) $request->url(),
                'method' => $request->method(),
                'headers' => $authentication->redactHeadersForLogging($headers),
                'body' => json_decode((string) $request->body(), true),
            ];
        });

        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            $json = $event->response->json();

            $this->capturedResponse = [
                'status' => $event->response->status(),
                'body' => is_array($json) ? $json : $event->response->body(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function redactRequest(array $request): array
    {
        return $request;
    }
}
