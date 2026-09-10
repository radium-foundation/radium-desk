<?php

namespace App\Services\StatutoryInvoice\Whitebooks;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceIrnPayload;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Direct WhiteBooks Production API adapter.
 * Verified: GET /einvoice/authenticate, POST GENERATE V1_03,
 * GET GETIRNBYDOCDETAILS V1_03 (P-194: query param1+email, headers docnum/docdate).
 * Not OAuth. Not media.radiumbox.com. Production must not bind this class.
 */
final class WhitebooksEInvoiceGateway implements EInvoiceGateway
{
    public const AUTHENTICATE_PATH = '/einvoice/authenticate';

    public const GENERATE_PATH = '/einvoice/type/GENERATE/version/V1_03';

    public const GET_IRN_PATH = '/einvoice/type/GETIRNBYDOCDETAILS/version/V1_03';

    public function __construct(
        private readonly WhitebooksCredentialResolver $credentials,
        private readonly WhitebooksNicPayloadFactory $nic,
        private readonly WhitebooksResponseMapper $responses,
    ) {}

    public function provider(): string
    {
        return 'whitebooks';
    }

    public function submit(StatutoryInvoice $invoice, EInvoiceIrnPayload $payload): EInvoiceSubmitResult
    {
        $account = $this->credentials->forInvoice($invoice);
        if ($account === null) {
            return EInvoiceSubmitResult::permanentFailure(
                $this->provider(),
                ['reason' => 'missing_issuer_credentials', 'gaps' => $this->credentials->missingReasons($invoice)],
            );
        }

        $body = $this->nic->generateBody($payload);
        if ($body === null) {
            return EInvoiceSubmitResult::permanentFailure(
                $this->provider(),
                ['reason' => 'irp_fields_incomplete', 'gaps' => $payload->gaps],
            );
        }

        $token = $this->authenticate($account);
        if ($token instanceof EInvoiceSubmitResult) {
            return $token;
        }

        return $this->generate($account, $token, $body);
    }

    public function fetchExisting(StatutoryInvoice $invoice, EInvoiceIrnPayload $payload): EInvoiceSubmitResult
    {
        $account = $this->credentials->forInvoice($invoice);
        if ($account === null) {
            return EInvoiceSubmitResult::permanentFailure(
                $this->provider(),
                ['reason' => 'missing_issuer_credentials', 'gaps' => $this->credentials->missingReasons($invoice)],
            );
        }

        $lookup = $this->nic->documentLookup($payload);
        if ($lookup === null) {
            return EInvoiceSubmitResult::ambiguous(
                $this->provider(),
                ['reason' => 'missing_document_identity'],
            );
        }

        $token = $this->authenticate($account);
        if ($token instanceof EInvoiceSubmitResult) {
            return $this->fetchAuthFailure($token);
        }

        return $this->getIrnByDocument($account, $token, $lookup);
    }

    public function cancel(StatutoryInvoice $invoice, string $reason): void
    {
        // First release does not cancel IRN.
    }

    private function fetchAuthFailure(EInvoiceSubmitResult $auth): EInvoiceSubmitResult
    {
        if ($auth->outcome->value === 'temporary_failure') {
            return EInvoiceSubmitResult::temporaryFailure(
                $this->provider(),
                ['reason' => 'authenticate_failed', 'detail' => $auth->payload],
                $auth->correlationId,
            );
        }

        return $auth;
    }

    private function authenticate(WhitebooksCredentialSet $account): string|EInvoiceSubmitResult
    {
        try {
            $response = $this->client()
                ->withHeaders($this->authHeaders($account))
                ->get($account->baseUrl.self::AUTHENTICATE_PATH, ['email' => $account->email]);
        } catch (ConnectionException) {
            return EInvoiceSubmitResult::temporaryFailure(
                $this->provider(),
                ['reason' => 'authenticate_timeout'],
            );
        } catch (Throwable) {
            return EInvoiceSubmitResult::temporaryFailure(
                $this->provider(),
                ['reason' => 'authenticate_transport_error'],
            );
        }

        $classified = $this->classifyHttp($response, 'authenticate', generateSubmitted: false);
        if ($classified !== null) {
            return $classified;
        }

        $token = $this->responses->authToken($response->json() ?? []);
        if ($token === null) {
            return EInvoiceSubmitResult::permanentFailure(
                $this->provider(),
                ['reason' => 'missing_auth_token', 'http_status' => $response->status()],
            );
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function generate(WhitebooksCredentialSet $account, string $token, array $body): EInvoiceSubmitResult
    {
        try {
            $response = $this->client()
                ->withHeaders($this->signedHeaders($account, $token))
                ->post($account->baseUrl.self::GENERATE_PATH.'?email='.rawurlencode($account->email), $body);
        } catch (ConnectionException) {
            return EInvoiceSubmitResult::ambiguous(
                $this->provider(),
                ['reason' => 'generate_timeout'],
            );
        } catch (Throwable) {
            return EInvoiceSubmitResult::ambiguous(
                $this->provider(),
                ['reason' => 'generate_transport_error'],
            );
        }

        $classified = $this->classifyHttp($response, 'generate', generateSubmitted: true);
        if ($classified !== null) {
            return $classified;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return EInvoiceSubmitResult::permanentFailure(
                $this->provider(),
                ['reason' => 'malformed_generate_response', 'http_status' => $response->status()],
            );
        }

        return $this->responses->mapGenerate($json);
    }

    /**
     * GETIRNBYDOCDETAILS recovery (P-194 production success path).
     * Query is param1 (document type) + email. Document number/date are headers.
     * Password is not sent. Error JSON remains unverified; HTTP classification
     * stays the existing conservative adapter mapping and does not retry.
     *
     * @param  array{doctype: string, docnum: string, docdate: string}  $lookup
     */
    private function getIrnByDocument(WhitebooksCredentialSet $account, string $token, array $lookup): EInvoiceSubmitResult
    {
        try {
            $response = $this->client()
                ->withHeaders($this->getIrnHeaders($account, $token, $lookup))
                ->get($account->baseUrl.self::GET_IRN_PATH, [
                    'param1' => $lookup['doctype'],
                    'email' => $account->email,
                ]);
        } catch (ConnectionException) {
            return EInvoiceSubmitResult::temporaryFailure(
                $this->provider(),
                ['reason' => 'get_irn_timeout'],
            );
        } catch (Throwable) {
            return EInvoiceSubmitResult::temporaryFailure(
                $this->provider(),
                ['reason' => 'get_irn_transport_error'],
            );
        }

        if ($response->status() === 404) {
            return EInvoiceSubmitResult::ambiguous(
                $this->provider(),
                ['reason' => 'not_found', 'http_status' => 404],
            );
        }

        $classified = $this->classifyHttp($response, 'get_irn', generateSubmitted: false);
        if ($classified !== null) {
            return $classified;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return EInvoiceSubmitResult::temporaryFailure(
                $this->provider(),
                ['reason' => 'malformed_get_irn_response', 'http_status' => $response->status()],
            );
        }

        return $this->responses->mapFetch($json);
    }

    private function classifyHttp(Response $response, string $operation, bool $generateSubmitted): ?EInvoiceSubmitResult
    {
        $status = $response->status();
        if ($status === 401 || $status === 403) {
            return EInvoiceSubmitResult::permanentFailure(
                $this->provider(),
                ['reason' => $operation.'_unauthorized', 'http_status' => $status],
            );
        }
        if ($status === 429) {
            return EInvoiceSubmitResult::temporaryFailure(
                $this->provider(),
                ['reason' => $operation.'_rate_limited', 'http_status' => $status],
            );
        }
        if ($status >= 500) {
            if ($generateSubmitted) {
                return EInvoiceSubmitResult::ambiguous(
                    $this->provider(),
                    ['reason' => $operation.'_provider_5xx', 'http_status' => $status],
                );
            }

            return EInvoiceSubmitResult::temporaryFailure(
                $this->provider(),
                ['reason' => $operation.'_provider_5xx', 'http_status' => $status],
            );
        }
        if ($status >= 400) {
            return EInvoiceSubmitResult::permanentFailure(
                $this->provider(),
                ['reason' => $operation.'_validation_error', 'http_status' => $status],
            );
        }
        if ($status < 200 || $status >= 300) {
            return EInvoiceSubmitResult::permanentFailure(
                $this->provider(),
                ['reason' => $operation.'_unexpected_status', 'http_status' => $status],
            );
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(WhitebooksCredentialSet $account): array
    {
        return [
            'accept' => 'application/json',
            'username' => $account->username,
            'password' => $account->password,
            'ip_address' => $account->ipAddress,
            'client_id' => $account->clientId,
            'client_secret' => $account->clientSecret,
            'gstin' => $account->gstin,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(WhitebooksCredentialSet $account, string $token): array
    {
        return [
            'accept' => 'application/json',
            'ip_address' => $account->ipAddress,
            'client_id' => $account->clientId,
            'client_secret' => $account->clientSecret,
            'username' => $account->username,
            'auth-token' => $token,
            'gstin' => $account->gstin,
        ];
    }

    /**
     * @param  array{doctype: string, docnum: string, docdate: string}  $lookup
     * @return array<string, string>
     */
    private function getIrnHeaders(WhitebooksCredentialSet $account, string $token, array $lookup): array
    {
        return $this->signedHeaders($account, $token) + [
            'docnum' => $lookup['docnum'],
            'docdate' => $lookup['docdate'],
        ];
    }

    private function client(): PendingRequest
    {
        $timeout = (int) config('statutory_invoices.einvoice.timeout_seconds', 30);

        return Http::acceptJson()
            ->asJson()
            ->timeout($timeout > 0 ? $timeout : 30)
            ->connectTimeout(10)
            ->withUserAgent('RadiumDesk/statutory-einvoice');
    }
}
