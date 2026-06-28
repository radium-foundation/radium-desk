@props([
    'order',
])

<div class="order-workspace-quick-actions">
    <button type="button" class="order-workspace-action-btn" disabled title="Coming soon">
        <i class="bi bi-telephone-fill" aria-hidden="true"></i>
        <span>Call</span>
    </button>
    <button type="button" class="order-workspace-action-btn" disabled title="Coming soon">
        <i class="bi bi-whatsapp" aria-hidden="true"></i>
        <span>WhatsApp</span>
    </button>
    <button type="button" class="order-workspace-action-btn" disabled title="Coming soon">
        <i class="bi bi-envelope-fill" aria-hidden="true"></i>
        <span>Email</span>
    </button>
    @can('create', App\Models\Incident::class)
        <a href="{{ route('orders.service-cases.create', $order) }}"
           class="order-workspace-action-btn order-workspace-action-btn--primary">
            <i class="bi bi-ticket-detailed-fill" aria-hidden="true"></i>
            <span>Create Ticket</span>
        </a>
    @else
        <button type="button" class="order-workspace-action-btn" disabled title="Not permitted">
            <i class="bi bi-ticket-detailed-fill" aria-hidden="true"></i>
            <span>Create Ticket</span>
        </button>
    @endcan
    <button type="button" class="order-workspace-action-btn" disabled title="Coming soon">
        <i class="bi bi-receipt" aria-hidden="true"></i>
        <span>Invoice</span>
    </button>
    <div class="dropdown">
        <button type="button"
                class="order-workspace-action-btn dropdown-toggle"
                data-bs-toggle="dropdown"
                aria-expanded="false">
            <i class="bi bi-three-dots" aria-hidden="true"></i>
            <span>More</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            @can('update', $order)
                <li>
                    <a class="dropdown-item" href="{{ route('orders.edit', $order) }}">
                        <i class="bi bi-pencil me-2"></i> Edit Order
                    </a>
                </li>
            @endcan
            @can('delete', $order)
                <li>
                    <form method="POST" action="{{ route('orders.destroy', $order) }}"
                          onsubmit="return confirm('Are you sure you want to delete this order?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-trash me-2"></i> Delete Order
                        </button>
                    </form>
                </li>
            @endcan
            <li><hr class="dropdown-divider"></li>
            <li><span class="dropdown-item-text text-muted small">More integrations coming soon</span></li>
        </ul>
    </div>
</div>
