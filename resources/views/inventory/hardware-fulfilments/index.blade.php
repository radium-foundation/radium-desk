@extends('layouts.app')

@section('title', 'Hardware fulfilment')

@section('content')
    @php
        $queue = $queue ?? 'work';
        $summary = $awaitingSummary;
        $work = $workSummary;
    @endphp

    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">Hardware fulfilment</h1>
        <p class="text-muted mb-0">
            Work Queue is the single operational list: pending RDE hardware from 5 September 2026 through now, plus every existing fulfilment.
            Process one order at a time on the fulfilment page. This page does not create fulfilments, serials, invoices, shipments, AWBs, pickups, or manifests.
        </p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'hardware-fulfilments'])

    <ul class="nav nav-pills gap-2 mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <a @class(['nav-link', 'active' => $queue === 'work']) href="{{ route('inventory.hardware-fulfilments.index', ['queue' => 'work']) }}">
                Work Queue
                <span class="badge text-bg-light text-dark ms-1">{{ $work->qualifying + $work->blockedReview }}</span>
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a @class(['nav-link', 'active' => $queue === 'open']) href="{{ route('inventory.hardware-fulfilments.index', ['queue' => 'open']) }}">
                Open Fulfilments
                <span class="badge text-bg-light text-dark ms-1">{{ $summary->withFulfilment }}</span>
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a @class(['nav-link', 'active' => $queue === 'awaiting']) href="{{ route('inventory.hardware-fulfilments.index', ['queue' => 'awaiting']) }}">
                Awaiting Fulfilment
                <span class="badge text-bg-light text-dark ms-1">{{ $summary->reviewCandidates }}</span>
            </a>
        </li>
    </ul>

    @if($queue === 'work')
        <div class="alert alert-info">
            <p class="fw-semibold mb-1">Active date range (IST): {{ $work->fromIst }} → {{ $work->toIst }}</p>
            <p class="mb-1">
                Existing fulfilments are always listed, including in-progress orders such as RDE318421.
                Support orders without a fulfilment use Desk <code>orders.created_at</code> in this window.
            </p>
            <p class="small mb-0 text-muted">
                Qualifying: {{ $work->qualifying }}
                · Blocked / review: {{ $work->blockedReview }}
                · Excluded from this list: {{ $work->excluded }}
                (unpaid {{ $work->excludedUnpaid }}, already completed on Desk {{ $work->excludedCompleted }}, RIN {{ $work->excludedRin }})
            </p>
        </div>

        <form method="GET" action="{{ route('inventory.hardware-fulfilments.index') }}" class="card border-0 shadow-sm mb-3">
            <input type="hidden" name="queue" value="work">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="hf-work-order">Order</label>
                        <input id="hf-work-order" type="search" name="order" value="{{ $filters['order'] }}" class="form-control" placeholder="RDE source id">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="hf-work-from">From (IST)</label>
                        <input id="hf-work-from" type="date" name="from" value="{{ $filters['from'] }}" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="hf-work-to">To (IST)</label>
                        <input id="hf-work-to" type="date" name="to" value="{{ $filters['to'] }}" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="hf-work-stage">Stage</label>
                        <select id="hf-work-stage" name="stage" class="form-select">
                            <option value="">All stages</option>
                            @foreach($stages as $stage)
                                <option value="{{ $stage->value }}" @selected($filters['stage'] === $stage->value)>{{ $stage->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="hf-work-payment">Payment</label>
                        <select id="hf-work-payment" name="payment" class="form-select">
                            <option value="">All</option>
                            <option value="paid" @selected($filters['payment'] === 'paid')>Paid</option>
                            <option value="unpaid" @selected($filters['payment'] === 'unpaid')>Unpaid / not verified</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-outline-primary w-100">Filter</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Order date</th>
                            <th>Customer</th>
                            <th>Product / SKU</th>
                            <th>Qty</th>
                            <th>Payment</th>
                            <th>Fulfilment</th>
                            <th>Serial</th>
                            <th>Invoice</th>
                            <th>Shipment</th>
                            <th>AWB</th>
                            <th>Stage</th>
                            <th>Next action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($workRows as $row)
                            <tr>
                                <td class="fw-semibold">{{ $row->sourceId }}</td>
                                <td>{{ $row->orderDateIst }}</td>
                                <td>{{ $row->customer }}</td>
                                <td>
                                    {{ $row->product }}
                                    <div class="small text-muted">{{ $row->sku }}</div>
                                </td>
                                <td>{{ $row->quantity }}</td>
                                <td>{{ $row->payment }}</td>
                                <td>{{ $row->fulfilmentStatus }}</td>
                                <td>{{ $row->serialStatus }}</td>
                                <td>{{ $row->invoiceStatus }}</td>
                                <td>{{ $row->shipmentStatus }}</td>
                                <td>{{ $row->awbStatus }}</td>
                                <td>{{ $row->stage->label() }}</td>
                                <td>
                                    @if($row->nextUrl)
                                        <a href="{{ $row->nextUrl }}">{{ $row->nextAction }}</a>
                                    @else
                                        {{ $row->nextAction }}
                                    @endif
                                    @if($row->blocker)
                                        <div class="small text-muted">{{ $row->blocker }}</div>
                                    @endif
                                    @if(! $row->hasFulfilment)
                                        <div class="small">
                                            <a href="{{ $row->openOrderUrl() }}">Open order</a>
                                            <span class="text-muted">·</span>
                                            <a href="{{ $row->customer360Url() }}">Customer 360</a>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="text-muted">No hardware orders match this work-queue filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $workRows->links() }}</div>
    @elseif($queue === 'awaiting')
        <div class="alert alert-warning">
            <p class="fw-semibold mb-1">Do not create fulfilments from this page.</p>
            <p class="mb-1">Desk cannot confirm a Box physical line until a verified handoff is ingested. Open the order, review payment and product, then escalate a single eligible RDE for isolated ingest. Do not mass-ingest historical orders.</p>
            <p class="small mb-0 text-muted">
                RDE without fulfilment: {{ $summary->withoutFulfilment }}
                · Review candidates: {{ $summary->reviewCandidates }}
                · Frozen: {{ $summary->frozen }}
                · HOLD: {{ $summary->hold }}
                · Blocked: {{ $summary->blocked }}
                · Historical: {{ $summary->preCutoff }}
                · Already completed on Desk: {{ $summary->deskAlreadyCompleted }}
                · Unpaid: {{ $summary->unpaid }}
                · RIN (not listed here): {{ $summary->rin }}
            </p>
        </div>

        <form method="GET" action="{{ route('inventory.hardware-fulfilments.index') }}" class="card border-0 shadow-sm mb-3">
            <input type="hidden" name="queue" value="awaiting">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small mb-1" for="hf-awaiting-order">Order</label>
                        <input id="hf-awaiting-order" type="search" name="order" value="{{ $filters['order'] }}" class="form-control" placeholder="RDE source id">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small mb-1" for="hf-awaiting-reason">Reason</label>
                        <select id="hf-awaiting-reason" name="awaiting_reason" class="form-select">
                            <option value="review" @selected($filters['awaiting_reason'] === 'review')>Review candidates</option>
                            <option value="excluded" @selected($filters['awaiting_reason'] === 'excluded')>Frozen / HOLD / blocked</option>
                            <option value="historical" @selected($filters['awaiting_reason'] === 'historical')>Historical / pre-cutoff</option>
                            <option value="completed" @selected($filters['awaiting_reason'] === 'completed')>Already completed on Desk</option>
                            <option value="unpaid" @selected($filters['awaiting_reason'] === 'unpaid')>Unpaid</option>
                            <option value="all" @selected($filters['awaiting_reason'] === 'all')>All RDE without fulfilment</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-outline-primary w-100">Filter</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Payment</th>
                            <th>Created (IST)</th>
                            <th>Reason</th>
                            <th>Desk serial / transaction</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($awaiting as $row)
                            <tr>
                                <td class="fw-semibold">{{ $row->order->order_id }}</td>
                                <td>{{ $row->paid ? 'Paid' : 'Unpaid' }}</td>
                                <td>{{ $row->createdAtIst }}</td>
                                <td>
                                    {{ $row->reason->label() }}
                                    <div class="small text-muted">{{ $row->reason->operatorNote() }}</div>
                                </td>
                                <td>
                                    @if($row->hasSupportSerial)
                                        Serial on order
                                    @elseif($row->transactionLocked)
                                        Transaction locked
                                    @else
                                        None
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ $row->orderUrl() }}">Open order</a>
                                    <span class="text-muted">·</span>
                                    <a href="{{ $row->customer360Url() }}">Customer 360</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-muted">No RDE orders match this awaiting-fulfilment filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $awaiting->links() }}</div>
    @else
        <div class="alert alert-info">
            This list shows only Hardware Fulfilment records. It is not every RDE hardware order.
            Use Work Queue for the operational sequence, or Awaiting Fulfilment to review orders that still need ingest.
        </div>

        <form method="GET" action="{{ route('inventory.hardware-fulfilments.index') }}" class="card border-0 shadow-sm mb-3">
            <input type="hidden" name="queue" value="open">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="hf-filter-order">Order</label>
                        <input id="hf-filter-order" type="search" name="order" value="{{ $filters['order'] }}" class="form-control" placeholder="Order or source id">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="hf-filter-serial">Serial</label>
                        <input id="hf-filter-serial" type="search" name="serial" value="{{ $filters['serial'] }}" class="form-control" placeholder="Serial">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="hf-filter-state">Fulfilment state</label>
                        <select id="hf-filter-state" name="state" class="form-select">
                            <option value="">Open queue</option>
                            @foreach($states as $state)
                                <option value="{{ $state->value }}" @selected($filters['state'] === $state->value)>{{ strtoupper(str_replace('_', ' ', $state->value)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="hf-filter-branch">Branch</label>
                        <select id="hf-filter-branch" name="branch_id" class="form-select">
                            <option value="">All branches</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) $filters['branch_id'] === (string) $branch->id)>{{ $branch->code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="hf-filter-shipment">Shipment / readiness</label>
                        <select id="hf-filter-shipment" name="shipment_status" class="form-select">
                            <option value="">All</option>
                            <option value="ready" @selected($filters['shipment_status'] === 'ready')>Invoice issued, not shipped</option>
                            <option value="not_created" @selected($filters['shipment_status'] === 'not_created')>No shipment</option>
                            <option value="created" @selected($filters['shipment_status'] === 'created')>Created, no AWB</option>
                            <option value="awb" @selected($filters['shipment_status'] === 'awb')>AWB assigned</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-outline-primary w-100">Filter</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Commerce</th>
                            <th>Status</th>
                            <th>Physical branch</th>
                            <th>Shipment</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($fulfilments as $fulfilment)
                            <tr>
                                <td class="fw-semibold">{{ $fulfilment->source_id }}</td>
                                <td>{{ $fulfilment->commerceOrder?->order_no ?? '—' }}</td>
                                <td>{{ strtoupper(str_replace('_', ' ', $fulfilment->state?->value ?? '')) }}</td>
                                <td>{{ $fulfilment->fulfilmentBranch?->code ?? 'Unset' }}</td>
                                <td>
                                    @if($fulfilment->awb)
                                        AWB {{ $fulfilment->awb }}
                                    @elseif($fulfilment->shipment_id)
                                        Created
                                    @else
                                        Not created
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('inventory.hardware-fulfilments.show', $fulfilment) }}">
                                        {{ $fulfilment->state?->value === 'ready_for_fulfilment' ? 'Allocate serial' : 'Open' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-muted">No open hardware fulfilments. This is not every hardware order — use Work Queue or Awaiting Fulfilment.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $fulfilments->links() }}</div>
    @endif
@endsection
