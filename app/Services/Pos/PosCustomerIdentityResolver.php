<?php

namespace App\Services\Pos;

use App\Models\InventoryCustomer;
use App\Services\StatutoryInvoice\BuyerGstin;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

final class PosCustomerIdentityResolver
{
    public const RESOLUTION_SALE_ONLY = 'sale_only';

    public const RESOLUTION_UPDATE_MASTER = 'update_master';

    /**
     * @param  array{name?: string, phone?: string, email?: string|null, gstin?: string|null}  $incoming
     */
    public function detectConflict(InventoryCustomer $existing, array $incoming): ?array
    {
        $incomingName = $this->normalizeName((string) ($incoming['name'] ?? ''));
        $existingName = $this->normalizeName((string) $existing->name);

        $incomingGstin = BuyerGstin::normalize(isset($incoming['gstin']) && is_string($incoming['gstin']) ? $incoming['gstin'] : null);
        $existingGstin = BuyerGstin::normalize($existing->gstin);

        $nameConflict = $incomingName !== '' && $existingName !== '' && $incomingName !== $existingName;
        $gstinConflict = $incomingGstin !== null
            && $existingGstin !== null
            && $incomingGstin !== $existingGstin;

        if (! $nameConflict && ! $gstinConflict) {
            return null;
        }

        return [
            'phone' => $existing->phone,
            'existing_name' => (string) $existing->name,
            'existing_gstin' => $existingGstin,
            'incoming_name' => trim((string) ($incoming['name'] ?? '')),
            'incoming_gstin' => $incomingGstin,
            'name_conflict' => $nameConflict,
            'gstin_conflict' => $gstinConflict,
        ];
    }

    /**
     * @param  array{name?: string, phone?: string, email?: string|null, gstin?: string|null}  $incoming
     */
    public function saleBuyerName(array $incoming): string
    {
        $name = trim((string) ($incoming['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'customer_name' => 'Customer name is required.',
            ]);
        }

        return $name;
    }

    /**
     * @param  array{name?: string, phone?: string, email?: string|null, gstin?: string|null}  $incoming
     */
    public function requireResolutionIfConflict(
        InventoryCustomer $existing,
        array $incoming,
        ?string $resolution,
    ): void {
        $conflict = $this->detectConflict($existing, $incoming);
        if ($conflict === null) {
            return;
        }

        if ($resolution === self::RESOLUTION_SALE_ONLY || $resolution === self::RESOLUTION_UPDATE_MASTER) {
            return;
        }

        Session::flash('pos_customer_identity_conflict', $conflict);

        throw ValidationException::withMessages([
            'customer_identity_conflict' => $this->conflictSummary($conflict),
        ]);
    }

    /**
     * @param  array{name?: string, phone?: string, email?: string|null, gstin?: string|null}  $incoming
     */
    public function resolveExistingCustomer(
        InventoryCustomer $existing,
        array $incoming,
        ?string $resolution,
    ): InventoryCustomer {
        $conflict = $this->detectConflict($existing, $incoming);

        if ($conflict === null) {
            $existing->fill($this->safeMasterUpdates($incoming, $existing));
            $existing->save();

            return $existing;
        }

        if ($resolution === self::RESOLUTION_SALE_ONLY) {
            $existing->fill($this->contactOnlyUpdates($incoming, $existing));
            $existing->save();

            return $existing;
        }

        if ($resolution === self::RESOLUTION_UPDATE_MASTER) {
            $existing->fill($this->fullMasterUpdates($incoming, $existing));
            $existing->save();

            return $existing;
        }

        Session::flash('pos_customer_identity_conflict', $conflict);

        throw ValidationException::withMessages([
            'customer_identity_conflict' => $this->conflictSummary($conflict),
        ]);
    }

    /**
     * @param  array{name?: string, phone?: string, email?: string|null, gstin?: string|null}  $incoming
     * @return array<string, mixed>
     */
    private function safeMasterUpdates(array $incoming, InventoryCustomer $existing): array
    {
        $updates = [
            'name' => trim((string) ($incoming['name'] ?? $existing->name)),
            'email' => array_key_exists('email', $incoming) ? ($incoming['email'] ?? $existing->email) : $existing->email,
        ];

        $gstin = BuyerGstin::normalize(isset($incoming['gstin']) && is_string($incoming['gstin']) ? $incoming['gstin'] : null);
        if ($gstin !== null) {
            $updates['gstin'] = $gstin;
        }

        return $updates;
    }

    /**
     * @param  array{name?: string, phone?: string, email?: string|null, gstin?: string|null}  $incoming
     * @return array<string, mixed>
     */
    private function contactOnlyUpdates(array $incoming, InventoryCustomer $existing): array
    {
        return [
            'email' => array_key_exists('email', $incoming) ? ($incoming['email'] ?? $existing->email) : $existing->email,
        ];
    }

    /**
     * @param  array{name?: string, phone?: string, email?: string|null, gstin?: string|null}  $incoming
     * @return array<string, mixed>
     */
    private function fullMasterUpdates(array $incoming, InventoryCustomer $existing): array
    {
        $updates = $this->safeMasterUpdates($incoming, $existing);
        $updates['name'] = trim((string) ($incoming['name'] ?? $existing->name));

        return $updates;
    }

    /**
     * @param  array{
     *     phone: string,
     *     existing_name: string,
     *     existing_gstin: ?string,
     *     incoming_name: string,
     *     incoming_gstin: ?string,
     *     name_conflict: bool,
     *     gstin_conflict: bool
     * }  $conflict
     */
    public function conflictSummary(array $conflict): string
    {
        return sprintf(
            'Phone %s is already linked to a different legal identity on the customer master (%s). The sale you entered uses %s. Choose how to proceed before completing the sale.',
            $conflict['phone'],
            $this->identityLabel($conflict['existing_name'], $conflict['existing_gstin']),
            $this->identityLabel($conflict['incoming_name'], $conflict['incoming_gstin']),
        );
    }

    private function identityLabel(string $name, ?string $gstin): string
    {
        if ($gstin !== null && $gstin !== '') {
            return sprintf('%s · GSTIN %s', $name, $gstin);
        }

        return $name;
    }

    private function normalizeName(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?? '';

        return mb_strtolower($collapsed);
    }
}
