@php
    use App\Enums\IncidentStatus;

    $isActive = in_array($incident->status, [IncidentStatus::Open, IncidentStatus::InProgress], true);
    $endAt = $isActive ? now() : ($incident->updated_at ?? $incident->created_at);

    if ($incident->created_at && $endAt) {
        $aging = $incident->created_at->diff($endAt);

        $tooltip = $isActive
            ? sprintf(
                'Pending for %d days %d hours %d minutes',
                $aging->days,
                $aging->h,
                $aging->i,
            )
            : sprintf(
                'Open for %d days %d hours before closure',
                $aging->days,
                $aging->h,
            );
    } else {
        $tooltip = 'Aging unavailable';
    }
@endphp

<i class="bi bi-info-circle text-muted ms-1"
   role="img"
   aria-label="Case aging"
   data-bs-toggle="tooltip"
   data-bs-placement="top"
   data-bs-title="{{ $tooltip }}"></i>
