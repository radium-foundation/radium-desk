<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareWorkspaceFilter;
use App\Enums\HardwareWorkspaceScope;
use App\Enums\ShiprocketTrackNormalized;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL equivalents of verified Needs Action classifier rules.
 * Read-only. Does not inspect shipment eligibility.
 */
final class HardwareNeedsActionSqlQuery
{
    private ?bool $hasProviderTrackColumn = null;

    /**
     * @return array<string, int>
     */
    public function filterCounts(Carbon $fromIst, Carbon $toIst, string $search = ''): array
    {
        $mapping = $this->mappingRequiredCount($fromIst, $toIst, $search);
        $awaitingSerial = $this->fulfilmentCount($this->awaitingSerialQuery($search));
        $awbPending = $this->fulfilmentCount($this->awbPendingQuery($search));
        $photo = $this->fulfilmentCount($this->packagePhotoPendingQuery($search));
        $shipping = $this->fulfilmentCount($this->shippingQuery($search));
        $completed = $this->fulfilmentCount($this->completedQuery($search));
        $outForPickup = $this->fulfilmentCount($this->outForPickupQuery($search));
        $pickedUp = $this->fulfilmentCount($this->pickedUpQuery($search));
        $inTransit = $this->fulfilmentCount($this->inTransitQuery($search));
        $readyForPickup = $this->fulfilmentCount($this->readyForPickupQuery($search));

        $needsAction = $mapping + $awaitingSerial + $awbPending + $photo;

        $counts = [];
        foreach (HardwareWorkspaceFilter::cases() as $filter) {
            $counts[$filter->value] = 0;
        }

        $counts[HardwareWorkspaceFilter::MappingRequired->value] = $mapping;
        $counts[HardwareWorkspaceFilter::AwaitingSerial->value] = $awaitingSerial;
        $counts[HardwareWorkspaceFilter::AwbPending->value] = $awbPending;
        $counts[HardwareWorkspaceFilter::PackagePhotoPending->value] = $photo;
        $counts[HardwareWorkspaceFilter::NeedsAction->value] = $needsAction;
        $counts[HardwareWorkspaceFilter::Shipping->value] = $shipping;
        $counts[HardwareWorkspaceFilter::OutForPickup->value] = $outForPickup;
        $counts[HardwareWorkspaceFilter::PickedUp->value] = $pickedUp;
        $counts[HardwareWorkspaceFilter::InTransit->value] = $inTransit;
        $counts[HardwareWorkspaceFilter::ReadyForPickup->value] = $readyForPickup;
        $counts[HardwareWorkspaceFilter::Completed->value] = $completed;
        $counts[HardwareWorkspaceFilter::Delivered->value] = $completed;

        return $counts;
    }

    /**
     * Identifier page for the selected queue. Does not load fulfilment models.
     *
     * @return array{
     *     fulfilment_ids: list<int>,
     *     order_ids: list<int>,
     *     total: int
     * }
     */
    public function page(
        HardwareWorkspaceScope $scope,
        HardwareWorkspaceFilter $filter,
        Carbon $fromIst,
        Carbon $toIst,
        string $search,
        int $page,
        int $perPage,
    ): array {
        if ($scope === HardwareWorkspaceScope::Shipped
            || in_array($filter, [HardwareWorkspaceFilter::Completed, HardwareWorkspaceFilter::Delivered], true)) {
            return $this->fulfilmentPage($this->completedQuery($search), $page, $perPage);
        }

        if ($filter->isShipping()) {
            $shippingQuery = match ($filter) {
                HardwareWorkspaceFilter::OutForPickup => $this->outForPickupQuery($search),
                HardwareWorkspaceFilter::PickedUp => $this->pickedUpQuery($search),
                HardwareWorkspaceFilter::InTransit => $this->inTransitQuery($search),
                HardwareWorkspaceFilter::ReadyForPickup => $this->readyForPickupQuery($search),
                default => $this->shippingQuery($search),
            };

            return $this->fulfilmentPage($shippingQuery, $page, $perPage);
        }

        $hfQuery = match ($filter) {
            HardwareWorkspaceFilter::MappingRequired => $this->mappingRequiredFulfilmentQuery($search),
            HardwareWorkspaceFilter::AwaitingSerial => $this->awaitingSerialQuery($search),
            HardwareWorkspaceFilter::AwbPending => $this->awbPendingQuery($search),
            HardwareWorkspaceFilter::PackagePhotoPending => $this->packagePhotoPendingQuery($search),
            HardwareWorkspaceFilter::NeedsAction => $this->activeNeedsActionFulfilmentQuery($search),
            default => $this->activeNeedsActionFulfilmentQuery($search),
        };

        $orderQueries = [];
        if (in_array($filter, [
            HardwareWorkspaceFilter::NeedsAction,
            HardwareWorkspaceFilter::MappingRequired,
        ], true)) {
            $orderQueries = [
                $this->awaitingMappingOrderQuery($fromIst, $toIst, $search),
                $this->windowedRinQuery($fromIst, $toIst, $search),
            ];
        }

        if ($orderQueries === []) {
            return $this->fulfilmentPage($hfQuery, $page, $perPage);
        }

        return $this->unionIdentifierPage($hfQuery, $orderQueries, $page, $perPage);
    }

    /**
     * Active-scope search: all non-ingested / non-shipped fulfilments plus
     * awaiting work-candidates and windowed RIN. Matches P-293: a non-empty
     * `q` shows Active matches, not only the selected chip.
     *
     * @return array{fulfilment_ids: list<int>, order_ids: list<int>, total: int}
     */
    public function searchActivePage(
        Carbon $fromIst,
        Carbon $toIst,
        string $search,
        int $page,
        int $perPage,
    ): array {
        return $this->unionIdentifierPage(
            $this->activeFulfilmentQuery($search),
            [
                $this->workCandidateOrderQuery($fromIst, $toIst, $search),
                $this->windowedRinQuery($fromIst, $toIst, $search),
            ],
            $page,
            $perPage,
        );
    }

    /**
     * @return list<int>
     */
    public function shippingCandidateIds(string $search = ''): array
    {
        return $this->shippingQuery($search)
            ->orderByDesc('hardware_fulfilments.id')
            ->pluck('hardware_fulfilments.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function mappingRequiredCount(Carbon $fromIst, Carbon $toIst, string $search): int
    {
        return $this->fulfilmentCount($this->mappingRequiredFulfilmentQuery($search))
            + $this->awaitingMappingOrderQuery($fromIst, $toIst, $search)->count()
            + $this->windowedRinQuery($fromIst, $toIst, $search)->count();
    }

    private function activeNeedsActionFulfilmentQuery(string $search): Builder
    {
        return $this->activeFulfilmentQuery($search)->where(function (Builder $query): void {
            $query->where(function (Builder $mapping): void {
                $this->whereMissingAllocatedSerial($mapping);
                $this->whereMissingSkuMap($mapping);
            })->orWhere(function (Builder $serial): void {
                $this->whereMissingAllocatedSerial($serial);
                $this->whereHasSkuMap($serial);
            })->orWhere(function (Builder $awb): void {
                $this->constrainAwbPending($awb);
            })->orWhere(function (Builder $photo): void {
                $this->constrainPackagePhotoPending($photo);
            });
        });
    }

    private function mappingRequiredFulfilmentQuery(string $search): Builder
    {
        $query = $this->activeFulfilmentQuery($search);
        $this->whereMissingAllocatedSerial($query);
        $this->whereMissingSkuMap($query);

        return $query;
    }

    private function awaitingSerialQuery(string $search): Builder
    {
        $query = $this->activeFulfilmentQuery($search);
        $this->whereMissingAllocatedSerial($query);
        $this->whereHasSkuMap($query);

        return $query;
    }

    private function awbPendingQuery(string $search): Builder
    {
        $query = $this->activeFulfilmentQuery($search);
        $this->constrainAwbPending($query);

        return $query;
    }

    private function packagePhotoPendingQuery(string $search): Builder
    {
        $query = $this->activeFulfilmentQuery($search);
        $this->constrainPackagePhotoPending($query);

        return $query;
    }

    private function shippingQuery(string $search): Builder
    {
        $query = $this->activeFulfilmentQuery($search);
        $this->whereHasAllocatedSerial($query);
        $this->whereHasInvoice($query);
        $this->whereBoundShipment($query);
        $this->whereAwbFilled($query);
        $query->where(function (Builder $inner): void {
            $inner->whereNot(function (Builder $photo): void {
                $this->constrainPackagePhotoPending($photo);
            });
        });
        $this->whereShipmentTrackNotIn($query, [ShiprocketTrackNormalized::Delivered->value]);

        return $query;
    }

    private function outForPickupQuery(string $search): Builder
    {
        $query = $this->shippingQuery($search);
        $this->whereShipmentTrackIn($query, [ShiprocketTrackNormalized::OutForPickup->value]);

        return $query;
    }

    private function pickedUpQuery(string $search): Builder
    {
        $query = $this->shippingQuery($search);
        $this->whereShipmentTrackIn($query, [ShiprocketTrackNormalized::PickedUp->value]);

        return $query;
    }

    private function inTransitQuery(string $search): Builder
    {
        $query = $this->shippingQuery($search);
        $this->whereShipmentTrackIn($query, [ShiprocketTrackNormalized::InTransit->value]);

        return $query;
    }

    private function readyForPickupQuery(string $search): Builder
    {
        $query = $this->shippingQuery($search);
        $query->whereNotNull('hardware_fulfilments.ready_for_pickup_at');
        $this->whereShipmentTrackNotIn($query, [
            ShiprocketTrackNormalized::OutForPickup->value,
            ShiprocketTrackNormalized::PickedUp->value,
            ShiprocketTrackNormalized::InTransit->value,
            ShiprocketTrackNormalized::Delivered->value,
        ]);

        return $query;
    }

    private function completedQuery(string $search): Builder
    {
        $query = HardwareFulfilment::query()
            ->whereIn('state', [
                HardwareFulfilmentState::Shipped->value,
                HardwareFulfilmentState::Synced->value,
            ]);
        $this->applyFulfilmentSearch($query, $search);

        return $query;
    }

    private function constrainAwbPending(Builder $query): void
    {
        $this->whereHasAllocatedSerial($query);
        $this->whereHasInvoice($query);
        $this->whereBoundShipment($query);
        $this->whereAwbEmpty($query);
    }

    private function constrainPackagePhotoPending(Builder $query): void
    {
        $this->whereHasAllocatedSerial($query);
        $this->whereHasInvoice($query);
        $this->whereBoundShipment($query);
        $this->whereAwbFilled($query);
        $query->whereExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('shipments')
                ->where(function ($join): void {
                    $join->whereColumn('shipments.id', 'hardware_fulfilments.shipment_id')
                        ->orWhereColumn('shipments.hardware_fulfilment_id', 'hardware_fulfilments.id');
                })
                ->whereNotNull('shipments.label_url')
                ->where('shipments.label_url', '!=', '')
                ->whereNotNull('shipments.pickup_requested_at')
                ->where(function ($manifest): void {
                    $manifest->where(function ($url): void {
                        $url->whereNotNull('shipments.manifest_url')
                            ->where('shipments.manifest_url', '!=', '');
                    })->orWhere(function ($id): void {
                        $id->whereNotNull('shipments.manifest_id')
                            ->where('shipments.manifest_id', '!=', '');
                    });
                });
        });
        $query->whereNotExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('hardware_fulfilment_package_evidences')
                ->whereColumn('hardware_fulfilment_package_evidences.hardware_fulfilment_id', 'hardware_fulfilments.id')
                ->whereIn('hardware_fulfilment_package_evidences.kind', [
                    HardwareFulfilmentPackageEvidenceKind::PackageBeforeLabel->value,
                    HardwareFulfilmentPackageEvidenceKind::PackageLabelApplied->value,
                ]);
        });
    }

    private function activeFulfilmentQuery(string $search): Builder
    {
        $query = HardwareFulfilment::query()
            ->whereNotIn('hardware_fulfilments.state', [
                HardwareFulfilmentState::Ingested->value,
                HardwareFulfilmentState::Shipped->value,
                HardwareFulfilmentState::Synced->value,
            ])
            ->whereNotIn('hardware_fulfilments.source_id', $this->ownerBlockedSourceIds());
        $this->applyFulfilmentSearch($query, $search);

        return $query;
    }

    private function whereMissingAllocatedSerial(Builder $query): void
    {
        $query->whereNotExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('hardware_fulfilment_serials')
                ->whereColumn('hardware_fulfilment_serials.hardware_fulfilment_id', 'hardware_fulfilments.id')
                ->where('hardware_fulfilment_serials.status', HardwareFulfilmentSerialStatus::Allocated->value)
                ->whereNotNull('hardware_fulfilment_serials.serial_number')
                ->where('hardware_fulfilment_serials.serial_number', '!=', '');
        });
    }

    private function whereHasAllocatedSerial(Builder $query): void
    {
        $query->whereExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('hardware_fulfilment_serials')
                ->whereColumn('hardware_fulfilment_serials.hardware_fulfilment_id', 'hardware_fulfilments.id')
                ->where('hardware_fulfilment_serials.status', HardwareFulfilmentSerialStatus::Allocated->value)
                ->whereNotNull('hardware_fulfilment_serials.serial_number')
                ->where('hardware_fulfilment_serials.serial_number', '!=', '');
        });
    }

    private function whereHasInvoice(Builder $query): void
    {
        $query->whereExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('statutory_invoices')
                ->leftJoin('commerce_orders as invoice_commerce', 'invoice_commerce.id', '=', 'hardware_fulfilments.commerce_order_id')
                ->where(function ($invoice): void {
                    $invoice->whereColumn('statutory_invoices.id', 'hardware_fulfilments.statutory_invoice_id')
                        ->orWhereColumn('statutory_invoices.id', 'invoice_commerce.statutory_invoice_id');
                })
                ->whereNotNull('statutory_invoices.invoice_number')
                ->where('statutory_invoices.invoice_number', '!=', '');
        });
    }

    private function whereBoundShipment(Builder $query): void
    {
        $query->whereExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('shipments')
                ->where(function ($join): void {
                    $join->whereColumn('shipments.id', 'hardware_fulfilments.shipment_id')
                        ->orWhereColumn('shipments.hardware_fulfilment_id', 'hardware_fulfilments.id');
                })
                ->whereNotNull('shipments.external_order_id')
                ->where('shipments.external_order_id', '!=', '')
                ->whereNotNull('shipments.external_shipment_id')
                ->where('shipments.external_shipment_id', '!=', '');
        });
    }

    private function whereAwbFilled(Builder $query): void
    {
        $query->where(function (Builder $awb): void {
            $awb->where(function (Builder $hf): void {
                $hf->whereNotNull('hardware_fulfilments.awb')
                    ->where('hardware_fulfilments.awb', '!=', '');
            })->orWhereExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('shipments')
                    ->where(function ($join): void {
                        $join->whereColumn('shipments.id', 'hardware_fulfilments.shipment_id')
                            ->orWhereColumn('shipments.hardware_fulfilment_id', 'hardware_fulfilments.id');
                    })
                    ->whereNotNull('shipments.awb')
                    ->where('shipments.awb', '!=', '');
            });
        });
    }

    private function whereAwbEmpty(Builder $query): void
    {
        $query->where(function (Builder $awb): void {
            $awb->whereNull('hardware_fulfilments.awb')
                ->orWhere('hardware_fulfilments.awb', '');
        })->whereNotExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('shipments')
                ->where(function ($join): void {
                    $join->whereColumn('shipments.id', 'hardware_fulfilments.shipment_id')
                        ->orWhereColumn('shipments.hardware_fulfilment_id', 'hardware_fulfilments.id');
                })
                ->whereNotNull('shipments.awb')
                ->where('shipments.awb', '!=', '');
        });
    }

    /**
     * @param  list<string>  $values
     */
    private function whereShipmentTrackIn(Builder $query, array $values): void
    {
        if (! $this->shipmentsHaveProviderTrack()) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereExists(function ($sub) use ($values): void {
            $this->constrainBoundShipment($sub);
            $sub->whereIn('shipments.provider_track_normalized', $values);
        });
    }

    /**
     * @param  list<string>  $values
     */
    private function whereShipmentTrackNotIn(Builder $query, array $values): void
    {
        if (! $this->shipmentsHaveProviderTrack()) {
            return;
        }

        $query->whereExists(function ($sub) use ($values): void {
            $this->constrainBoundShipment($sub);
            $sub->where(function ($track) use ($values): void {
                $track->whereNull('shipments.provider_track_normalized')
                    ->orWhereNotIn('shipments.provider_track_normalized', $values);
            });
        });
    }

    private function constrainBoundShipment($sub): void
    {
        $sub->selectRaw('1')
            ->from('shipments')
            ->where(function ($join): void {
                $join->whereColumn('shipments.id', 'hardware_fulfilments.shipment_id')
                    ->orWhereColumn('shipments.hardware_fulfilment_id', 'hardware_fulfilments.id');
            });
    }

    private function shipmentsHaveProviderTrack(): bool
    {
        return $this->hasProviderTrackColumn ??= Schema::hasColumn('shipments', 'provider_track_normalized');
    }

    private function whereMissingSkuMap(Builder $query): void
    {
        $query->where($this->missingSkuMapConstraint());
    }

    private function whereHasSkuMap(Builder $query): void
    {
        $query->whereNot($this->missingSkuMapConstraint());
    }

    /**
     * @return \Closure(Builder): void
     */
    private function missingSkuMapConstraint(): \Closure
    {
        return function (Builder $query): void {
            $query->whereExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('commerce_order_items as coi')
                    ->whereColumn('coi.commerce_order_id', 'hardware_fulfilments.commerce_order_id')
                    ->where(function ($physical): void {
                        $physical->where('coi.shipping_line_kind', HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND)
                            ->orWhereNotNull('coi.model_id');
                    })
                    ->where(function ($missing): void {
                        $missing->whereNull('coi.model_id')
                            ->orWhere('coi.model_id', '<', 1)
                            ->orWhereNotExists(function ($map): void {
                                $map->selectRaw('1')
                                    ->from('channel_sku_maps')
                                    ->join('inventory_products', 'inventory_products.id', '=', 'channel_sku_maps.inventory_product_id')
                                    ->whereColumn('channel_sku_maps.channel', 'hardware_fulfilments.channel')
                                    ->whereColumn('channel_sku_maps.model_id', 'coi.model_id')
                                    ->where('inventory_products.is_active', true);
                            });
                    });
            });
        };
    }

    private function awaitingMappingOrderQuery(Carbon $fromIst, Carbon $toIst, string $search): Builder
    {
        $fromBound = HardwareFulfilmentEligibility::createdAtSqlBound($fromIst);
        $toBound = HardwareFulfilmentEligibility::createdAtSqlBound($toIst);

        $query = $this->rdeWithoutFulfilment()
            ->cashfreeVerified()
            ->whereNotIn('orders.order_id', $this->ownerBlockedSourceIds())
            ->where(function (Builder $inner): void {
                $inner->whereNull('orders.serial_number')->orWhere('orders.serial_number', '');
            })
            ->where(function (Builder $inner): void {
                $inner->whereNull('orders.transaction_id')->orWhere('orders.transaction_id', '');
            })
            ->where('orders.created_at', '>=', $fromBound)
            ->where('orders.created_at', '<=', $toBound)
            ->whereExists(function ($commerce): void {
                $commerce->selectRaw('1')
                    ->from('commerce_orders')
                    ->where(function ($join): void {
                        $join->whereColumn('commerce_orders.support_order_id', 'orders.id')
                            ->orWhereColumn('commerce_orders.source_id', 'orders.order_id');
                    })
                    ->where(function ($tender): void {
                        $tender->whereNull('commerce_orders.wallet_tender_amount')
                            ->orWhere('commerce_orders.wallet_tender_amount', '<', 0.01);
                    })
                    ->whereExists(function ($item): void {
                        $item->selectRaw('1')
                            ->from('commerce_order_items as coi')
                            ->whereColumn('coi.commerce_order_id', 'commerce_orders.id')
                            ->where(function ($physical): void {
                                $physical->where('coi.shipping_line_kind', HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND)
                                    ->orWhereNotNull('coi.model_id');
                            })
                            ->where(function ($missing): void {
                                $missing->whereNull('coi.model_id')
                                    ->orWhere('coi.model_id', '<', 1)
                                    ->orWhereNotExists(function ($map): void {
                                        $map->selectRaw('1')
                                            ->from('channel_sku_maps')
                                            ->join('inventory_products', 'inventory_products.id', '=', 'channel_sku_maps.inventory_product_id')
                                            ->whereColumn('channel_sku_maps.channel', 'commerce_orders.channel')
                                            ->whereColumn('channel_sku_maps.model_id', 'coi.model_id')
                                            ->where('inventory_products.is_active', true);
                                    });
                            });
                    });
            });
        $this->applyOrderSearch($query, $search);

        return $query;
    }

    private function windowedRinQuery(Carbon $fromIst, Carbon $toIst, string $search): Builder
    {
        $fromBound = HardwareFulfilmentEligibility::createdAtSqlBound($fromIst);
        $toBound = HardwareFulfilmentEligibility::createdAtSqlBound($toIst);

        $query = Order::query()
            ->where('orders.order_id', 'like', 'RIN%')
            ->where('orders.created_at', '>=', $fromBound)
            ->where('orders.created_at', '<=', $toBound)
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('hardware_fulfilments')
                    ->whereColumn('hardware_fulfilments.source_id', 'orders.order_id');
            });
        $this->applyOrderSearch($query, $search);

        return $query;
    }

    private function rdeWithoutFulfilment(): Builder
    {
        return Order::query()
            ->where(function (Builder $query): void {
                foreach (HardwareFulfilmentEligibility::HARDWARE_SOURCE_PREFIXES as $prefix) {
                    $query->orWhere('orders.order_id', 'like', $prefix.'%');
                }
            })
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('hardware_fulfilments')
                    ->whereColumn('hardware_fulfilments.source_id', 'orders.order_id');
            })
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('hardware_fulfilments')
                    ->whereNotNull('hardware_fulfilments.support_order_id')
                    ->whereColumn('hardware_fulfilments.support_order_id', 'orders.id');
            });
    }

    private function applyFulfilmentSearch(Builder $query, string $search): void
    {
        $needle = trim($search);
        if ($needle === '') {
            return;
        }

        $like = '%'.$needle.'%';
        $query->where(function (Builder $inner) use ($like): void {
            $inner->where('hardware_fulfilments.source_id', 'like', $like)
                ->orWhereExists(function ($sub) use ($like): void {
                    $sub->selectRaw('1')
                        ->from('commerce_orders')
                        ->whereColumn('commerce_orders.id', 'hardware_fulfilments.commerce_order_id')
                        ->where('commerce_orders.customer_name', 'like', $like);
                })
                ->orWhereExists(function ($sub) use ($like): void {
                    $sub->selectRaw('1')
                        ->from('hardware_fulfilment_serials')
                        ->whereColumn('hardware_fulfilment_serials.hardware_fulfilment_id', 'hardware_fulfilments.id')
                        ->where('hardware_fulfilment_serials.serial_number', 'like', $like);
                })
                ->orWhereExists(function ($sub) use ($like): void {
                    $sub->selectRaw('1')
                        ->from('commerce_order_items as search_coi')
                        ->whereColumn('search_coi.commerce_order_id', 'hardware_fulfilments.commerce_order_id')
                        ->where(function ($product) use ($like): void {
                            $product->where('search_coi.sku', 'like', $like)
                                ->orWhere('search_coi.catalog_sku', 'like', $like)
                                ->orWhere('search_coi.description', 'like', $like);
                        });
                });
        });
    }

    private function applyOrderSearch(Builder $query, string $search): void
    {
        $needle = trim($search);
        if ($needle === '') {
            return;
        }

        $like = '%'.$needle.'%';
        $query->where(function (Builder $inner) use ($like): void {
            $inner->where('orders.order_id', 'like', $like)
                ->orWhere('orders.customer_name', 'like', $like);
        });
    }

    private function fulfilmentCount(Builder $query): int
    {
        return (clone $query)->count();
    }

    /**
     * @return array{fulfilment_ids: list<int>, order_ids: list<int>, total: int}
     */
    private function fulfilmentPage(Builder $query, int $page, int $perPage): array
    {
        $total = (clone $query)->count();
        $ids = (clone $query)
            ->orderByDesc('hardware_fulfilments.id')
            ->offset(max(0, ($page - 1) * $perPage))
            ->limit($perPage)
            ->pluck('hardware_fulfilments.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return [
            'fulfilment_ids' => $ids,
            'order_ids' => [],
            'total' => $total,
        ];
    }

    /**
     * Identifier UNION with SQL OFFSET/LIMIT. Does not load fulfilment models.
     *
     * @param  list<Builder>  $orderQueries
     * @return array{fulfilment_ids: list<int>, order_ids: list<int>, total: int}
     */
    private function unionIdentifierPage(Builder $hfQuery, array $orderQueries, int $page, int $perPage): array
    {
        $union = (clone $hfQuery)
            ->leftJoin('commerce_orders as hw_sort_co', 'hw_sort_co.id', '=', 'hardware_fulfilments.commerce_order_id')
            ->selectRaw("'hf' as kind, hardware_fulfilments.id as id, COALESCE(hw_sort_co.ordered_at, hardware_fulfilments.ingested_at) as sort_at");

        foreach ($orderQueries as $orderQuery) {
            $union = $union->unionAll(
                (clone $orderQuery)->selectRaw("'order' as kind, orders.id as id, orders.created_at as sort_at")
            );
        }

        $total = DB::query()->fromSub($union, 'hardware_identifier_page')->count();
        $slice = DB::query()->fromSub($union, 'hardware_identifier_page')
            ->orderByDesc('sort_at')
            ->offset(max(0, ($page - 1) * $perPage))
            ->limit($perPage)
            ->get();

        $fulfilmentIds = [];
        $orderIds = [];
        foreach ($slice as $row) {
            if ((string) $row->kind === 'hf') {
                $fulfilmentIds[] = (int) $row->id;
            } else {
                $orderIds[] = (int) $row->id;
            }
        }

        return [
            'fulfilment_ids' => $fulfilmentIds,
            'order_ids' => $orderIds,
            'total' => $total,
        ];
    }

    private function workCandidateOrderQuery(Carbon $fromIst, Carbon $toIst, string $search): Builder
    {
        $blocked = $this->ownerBlockedSourceIds();
        $fromBound = HardwareFulfilmentEligibility::createdAtSqlBound($fromIst);
        $toBound = HardwareFulfilmentEligibility::createdAtSqlBound($toIst);

        $query = $this->rdeWithoutFulfilment()
            ->where('orders.created_at', '>=', $fromBound)
            ->where('orders.created_at', '<=', $toBound)
            ->where(function (Builder $inner) use ($blocked): void {
                $inner->where(function (Builder $review) use ($blocked): void {
                    $review->whereNotIn('orders.order_id', $blocked)
                        ->where(function (Builder $paid): void {
                            $paid->whereNotNull('orders.cashfree_payment_id')
                                ->where('orders.cashfree_payment_id', '!=', '');
                        })
                        ->where(function (Builder $serial): void {
                            $serial->whereNull('orders.serial_number')->orWhere('orders.serial_number', '');
                        })
                        ->where(function (Builder $tx): void {
                            $tx->whereNull('orders.transaction_id')->orWhere('orders.transaction_id', '');
                        });
                });
                if ($blocked !== []) {
                    $inner->orWhereIn('orders.order_id', $blocked);
                }
            });
        $this->applyOrderSearch($query, $search);

        return $query;
    }

    /**
     * @return list<string>
     */
    private function ownerBlockedSourceIds(): array
    {
        return array_values(array_unique(array_merge(
            HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS,
            HardwareFulfilmentEligibility::HOLD_SOURCE_IDS,
            HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS,
        )));
    }
}
