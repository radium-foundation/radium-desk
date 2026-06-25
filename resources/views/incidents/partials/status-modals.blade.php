@can('update', $incident)
    <div class="modal fade" id="resolveModal" tabindex="-1" aria-labelledby="resolveModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                @include('service-cases.fragments.resolve-form', ['incident' => $incident])
            </div>
        </div>
    </div>

    <div class="modal fade" id="closeModal" tabindex="-1" aria-labelledby="closeModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                @include('service-cases.fragments.close-form', ['incident' => $incident])
            </div>
        </div>
    </div>
@endcan
