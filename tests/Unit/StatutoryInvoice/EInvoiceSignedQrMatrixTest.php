<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\EInvoiceSignedQrMatrix;
use PHPUnit\Framework\TestCase;

class EInvoiceSignedQrMatrixTest extends TestCase
{
    public function test_jwt_signed_qr_encodes_to_a_square_dark_matrix(): void
    {
        $matrix = (new EInvoiceSignedQrMatrix)->matrix($this->jwt());

        $this->assertIsArray($matrix);
        $this->assertGreaterThanOrEqual(21 + (EInvoiceSignedQrMatrix::QUIET_ZONE * 2), count($matrix));
        $this->assertCount(count($matrix), $matrix[0]);
        $dark = 0;
        foreach ($matrix as $row) {
            $this->assertCount(count($matrix), $row);
            foreach ($row as $cell) {
                $this->assertIsBool($cell);
                if ($cell) {
                    $dark++;
                }
            }
        }
        $this->assertGreaterThan(100, $dark);
    }

    public function test_production_length_jwt_encodes_without_using_live_signed_qr(): void
    {
        $header = 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCJ9';
        $jwt = $header.str_repeat('A', 150 - strlen($header)).'.'.str_repeat('B', 450).'.'.str_repeat('C', 342);
        $this->assertSame(944, strlen($jwt));

        $matrix = (new EInvoiceSignedQrMatrix)->matrix($jwt);

        $this->assertIsArray($matrix);
        $this->assertSame(count($matrix), count($matrix[0]));
        $this->assertGreaterThanOrEqual(21 + (EInvoiceSignedQrMatrix::QUIET_ZONE * 2), count($matrix));
    }

    public function test_empty_and_non_jwt_values_are_not_encoded(): void
    {
        $encoder = new EInvoiceSignedQrMatrix;

        $this->assertNull($encoder->matrix(null));
        $this->assertNull($encoder->matrix(''));
        $this->assertNull($encoder->matrix('   '));
        $this->assertNull($encoder->matrix('short'));
        $this->assertNull($encoder->matrix('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+n2lwAAAAASUVORK5CYII='));
        $this->assertNull($encoder->matrix(' '.$this->jwt()));
        $this->assertNull($encoder->encodablePayload($this->jwt().' '));
    }

    private function jwt(): string
    {
        return 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjoiZWluaXZvaWNlLXRlc3QtcGF5bG9hZC1maXh0dXJlIn0.dGVzdC1zaWduYXR1cmUtZml4dHVyZS1ub3QtcHJvZHVjdGlvbg';
    }
}
