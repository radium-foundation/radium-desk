<?php

namespace App\Support\ServicePos;

/**
 * Mapping specification for a future Admin → Desk service catalog import.
 * This class does not perform imports.
 */
final class AdminCatalogImportMapper
{
    /**
     * @param  array<string, mixed>  $adminProduct
     * @return array<string, mixed>
     */
    public function mapProductRow(array $adminProduct): array
    {
        return [
            'legacy_admin_product_id' => $adminProduct['id'] ?? null,
            'legacy_admin_attribute_id' => $adminProduct['attribute_id'] ?? null,
            'category_code' => $this->categoryCodeForAttribute($adminProduct['attribute_id'] ?? null),
            'code' => $adminProduct['sku_code'] ?? null,
            'name' => $adminProduct['product_name'] ?? null,
            'duration_label' => $adminProduct['short_name'] ?? null,
            'sac_code' => $adminProduct['hsn_code'] ?? null,
            'gst_rate' => $adminProduct['gst_percentage'] ?? null,
            'price_ex_gst' => $adminProduct['selling_price'] ?? null,
            'price_incl_gst' => $adminProduct['publish_price'] ?? null,
            'parent_admin_product_id' => $adminProduct['product_id'] ?? null,
            'is_active' => (int) ($adminProduct['status'] ?? 0) === 1,
            'metadata' => [
                'rdservice' => $adminProduct['rdservice'] ?? null,
                'warranty' => $adminProduct['warranty'] ?? null,
                'product_type' => $adminProduct['product_type'] ?? null,
            ],
        ];
    }

    public function requiresManualReview(array $mapped): bool
    {
        if (($mapped['sac_code'] ?? null) === null) {
            return true;
        }

        if (($mapped['category_code'] ?? null) === null) {
            return true;
        }

        if (($mapped['legacy_admin_attribute_id'] ?? null) === null && ($mapped['parent_admin_product_id'] ?? null) === null) {
            return true;
        }

        return false;
    }

    private function categoryCodeForAttribute(mixed $attributeId): ?string
    {
        return match ((int) $attributeId) {
            1 => 'rd_service',
            2 => 'amc',
            8 => 'installation',
            9 => 'other',
            default => null,
        };
    }
}
