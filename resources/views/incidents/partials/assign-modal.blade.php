@can('reassign', $incident)
    <div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                @include('service-cases.fragments.assign-form', [
                    'incident' => $incident,
                    'reassignableAdmins' => $reassignableAdmins,
                ])
            </div>
        </div>
    </div>
@endcan
