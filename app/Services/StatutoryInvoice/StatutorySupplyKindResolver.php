<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutorySupplyKind;
use Illuminate\Validation\ValidationException;

/**
 * Commerce lines are classified from HSN/SAC only.
 *
 * GST SAC codes begin with 99. Any other classifiable numeric HSN is a product.
 * Mixed or unclassifiable invoices fail closed. POS sales are always products.
 */
final class StatutorySupplyKindResolver
{
    public function fromHsnSac(?string $hsnSac): ?StatutorySupplyKind
    {
        $code = preg_replace('/\s+/', '', (string) $hsnSac) ?? '';
        if ($code === '' || preg_match('/^\d{4,8}$/', $code) !== 1) {
            return null;
        }

        return str_starts_with($code, '99')
            ? StatutorySupplyKind::Service
            : StatutorySupplyKind::Product;
    }

    /**
     * @param  list<string|null>  $hsnSacs
     */
    public function requireFromLines(array $hsnSacs): StatutorySupplyKind
    {
        if ($hsnSacs === []) {
            throw ValidationException::withMessages([
                'supply' => 'Statutory billing requires at least one classifiable HSN/SAC line.',
            ]);
        }

        $kinds = [];
        foreach ($hsnSacs as $hsnSac) {
            $kind = $this->fromHsnSac($hsnSac);
            if ($kind === null) {
                throw ValidationException::withMessages([
                    'supply' => 'A line HSN/SAC cannot be classified as a product or a service. Issuance fails closed.',
                ]);
            }
            $kinds[$kind->value] = $kind;
        }

        if (count($kinds) !== 1) {
            throw ValidationException::withMessages([
                'supply' => 'Mixed product and service lines cannot share one statutory issuer. Issuance fails closed.',
            ]);
        }

        return array_values($kinds)[0];
    }

    public function errorFromLines(array $hsnSacs): ?string
    {
        try {
            $this->requireFromLines($hsnSacs);
        } catch (ValidationException $exception) {
            return $this->firstMessage($exception);
        }

        return null;
    }

    private function firstMessage(ValidationException $exception): string
    {
        $errors = $exception->errors();
        $first = reset($errors);

        return is_array($first) ? (string) reset($first) : $exception->getMessage();
    }
}
