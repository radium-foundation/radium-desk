@forelse($rows as $row)
    @php
        $incidentId = $row->supportOrderId !== null
            ? ($incidentIds[$row->supportOrderId] ?? null)
            : null;
    @endphp
    @include('dashboard.partials.hardware-workspace-row', [
        'row' => $row,
        'incidentId' => $incidentId !== null ? (int) $incidentId : null,
        'operableFulfilmentIds' => $operableFulfilmentIds,
        'canOperateHardware' => $canOperateHardware,
        'isShippedScope' => $isShippedScope,
    ])
@empty
    <tr>
        <td colspan="8" class="dashboard-cases-empty">
            @if(($search ?? '') !== '')
                No hardware orders match this search.
            @elseif($isShippedScope)
                No shipped hardware orders in this view.
            @else
                No hardware orders in this queue.
            @endif
        </td>
    </tr>
@endforelse
