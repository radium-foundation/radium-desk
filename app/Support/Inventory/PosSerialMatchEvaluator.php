<?php

namespace App\Support\Inventory;

use App\Enums\InventorySerialStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryProductVariant;
use App\Models\InventorySerial;
use Illuminate\Support\Collection;

final class PosSerialMatchEvaluator
{
    /**
     * @param  list<string>  $serialNumbers
     * @return list<array{
     *     input: string,
     *     serial_number: string|null,
     *     status: string,
     *     message: string|null,
     * }>
     */
    public function evaluate(
        InventoryBranch $branch,
        InventoryProduct $product,
        ?InventoryProductVariant $variant,
        array $serialNumbers,
    ): array {
        $inputs = InventorySerialNumber::parseList($serialNumbers);
        if ($inputs === []) {
            return [];
        }

        /** @var Collection<string, InventorySerial> $byNumber */
        $byNumber = InventorySerial::query()
            ->whereIn('serial_number', $inputs)
            ->get()
            ->keyBy(fn (InventorySerial $serial): string => InventorySerialNumber::normalize($serial->serial_number));

        $results = [];
        foreach ($inputs as $input) {
            $serial = $byNumber->get($input);
            if ($serial === null) {
                $results[] = [
                    'input' => $input,
                    'serial_number' => null,
                    'status' => 'not_found',
                    'message' => 'Not found for this product.',
                ];

                continue;
            }

            if ($serial->product_id !== $product->id) {
                $results[] = [
                    'input' => $input,
                    'serial_number' => $serial->serial_number,
                    'status' => 'wrong_product',
                    'message' => 'Not found for this product.',
                ];

                continue;
            }

            if ((int) ($serial->variant_id ?? 0) !== (int) ($variant?->id ?? 0)) {
                $results[] = [
                    'input' => $input,
                    'serial_number' => $serial->serial_number,
                    'status' => 'wrong_variant',
                    'message' => 'Not found for this product.',
                ];

                continue;
            }

            if ($serial->branch_id !== $branch->id) {
                $results[] = [
                    'input' => $input,
                    'serial_number' => $serial->serial_number,
                    'status' => 'wrong_branch',
                    'message' => "Not available at {$branch->code}.",
                ];

                continue;
            }

            if ($serial->status !== InventorySerialStatus::Available) {
                $results[] = [
                    'input' => $input,
                    'serial_number' => $serial->serial_number,
                    'status' => 'unavailable',
                    'message' => ucfirst($serial->status->label()).'.',
                ];

                continue;
            }

            $results[] = [
                'input' => $input,
                'serial_number' => $serial->serial_number,
                'status' => 'available',
                'message' => null,
            ];
        }

        return $results;
    }
}
