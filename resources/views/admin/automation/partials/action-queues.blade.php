@props([
    'dashboard',
])

<section class="mb-4" aria-labelledby="action-queues-heading">
    <h2 id="action-queues-heading" class="h5 mb-3">Action Queues</h2>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h3 class="h6 mb-0">Waiting for Customer Serial</h3>
        </div>
        <div class="card-body p-0">
            @if($dashboard->waitingForCustomerSerialQueue === [])
                <div class="p-4 text-center text-muted">No cases waiting for customer serial.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Case</th>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Product</th>
                                <th>Current Agent</th>
                                <th>Age</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($dashboard->waitingForCustomerSerialQueue as $row)
                                <tr>
                                    <td>
                                        <a href="{{ $row['case_url'] }}" class="text-decoration-none">{{ $row['case_reference'] }}</a>
                                    </td>
                                    <td>
                                        @if($row['order_url'])
                                            <a href="{{ $row['order_url'] }}" class="text-decoration-none font-monospace">{{ $row['order_id'] }}</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $row['customer_name'] }}</td>
                                    <td>{{ $row['product'] }}</td>
                                    <td>{{ $row['agent_name'] }}</td>
                                    <td class="text-nowrap">{{ $row['age'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h3 class="h6 mb-0">Duplicate Serial Conflicts</h3>
        </div>
        <div class="card-body p-0">
            @if($dashboard->duplicateSerialConflicts === [])
                <div class="p-4 text-center text-muted">No duplicate serial conflicts.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Serial</th>
                                <th>Current Order</th>
                                <th>Conflicting Order</th>
                                <th>Product</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($dashboard->duplicateSerialConflicts as $row)
                                <tr>
                                    <td class="font-monospace">{{ $row['serial'] }}</td>
                                    <td>
                                        <a href="{{ $row['current_order_url'] }}" class="text-decoration-none font-monospace">{{ $row['current_order_id'] }}</a>
                                    </td>
                                    <td>
                                        @if($row['conflicting_order_url'])
                                            <a href="{{ $row['conflicting_order_url'] }}" class="text-decoration-none font-monospace">{{ $row['conflicting_order_id'] }}</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $row['product'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h3 class="h6 mb-0">RadiumBox Not Found</h3>
        </div>
        <div class="card-body p-0">
            @if($dashboard->radiumBoxNotFoundQueue === [])
                <div class="p-4 text-center text-muted">No RadiumBox not-found failures.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Product</th>
                                <th>Last Attempt</th>
                                <th>Failure Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($dashboard->radiumBoxNotFoundQueue as $row)
                                <tr>
                                    <td>
                                        <a href="{{ $row['order_url'] }}" class="text-decoration-none font-monospace">{{ $row['order_id'] }}</a>
                                    </td>
                                    <td>{{ $row['customer_name'] }}</td>
                                    <td>{{ $row['product'] }}</td>
                                    <td class="text-nowrap">{{ $row['last_attempt'] }}</td>
                                    <td>{{ $row['failure_reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</section>
