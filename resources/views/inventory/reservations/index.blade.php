@extends('layouts.app')

@section('title', 'Reservations')

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
            <h1 class="h3 mb-1">Reservations</h1>
            <p class="text-muted mb-0">Hold available serials so they cannot be sold elsewhere until released or consumed.</p>
        </div>
        <a href="{{ route('inventory.reservations.create') }}" class="btn btn-primary">Reserve serials</a>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'reservations'])

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Reservation</th>
                        <th>Branch</th>
                        <th>Status</th>
                        <th>By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($reservations as $reservation)
                        <tr>
                            <td>{{ $reservation->reservation_no }}</td>
                            <td>{{ $reservation->branch?->code }}</td>
                            <td>{{ $reservation->status->label() }}</td>
                            <td>{{ $reservation->createdBy?->name }}</td>
                            <td>
                                @if($reservation->status === \App\Enums\InventoryReservationStatus::Active)
                                    <form method="POST" action="{{ route('inventory.reservations.release', $reservation) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary">Release</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted p-4">No reservations yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $reservations->links() }}</div>
@endsection
