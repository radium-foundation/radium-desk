<form method="POST"
      action="{{ $formAction }}"
      data-hardware-action-form
      id="{{ $formId }}">
    @csrf
    <x-c360.section-card title="Summary" class="mb-2">
        <dl class="row small mb-0">
            @foreach($summary as $label => $value)
                <dt class="col-4">{{ $label }}</dt>
                <dd class="col-8">{{ $value }}</dd>
            @endforeach
        </dl>
    </x-c360.section-card>
    <x-c360.modal-footer>
        <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn c360-dialog-btn-primary" data-hardware-action-submit>
            {{ $submitLabel }}
        </button>
    </x-c360.modal-footer>
</form>
