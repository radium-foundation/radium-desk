<form method="POST"
      action="{{ route('inventory.hardware-fulfilments.package-evidence.store', $fulfilment) }}"
      data-hardware-action-form
      data-package-photo-upload
      data-package-photo-max-dimension="{{ (int) config('hardware_fulfilment.package_photo.client_max_dimension_px', 1600) }}"
      data-package-photo-target-kb="{{ (int) config('hardware_fulfilment.package_photo.client_target_kb', 140) }}"
      id="hardware-action-photo-form"
      enctype="multipart/form-data">
    @csrf
    <input type="hidden" name="kind" value="package_before_label">
    <x-c360.section-card title="Package Photo" class="mb-2">
        <p class="small text-muted">One package photo. This is evidence only and does not block shipment, pickup, or manifest.</p>
        <label class="form-label" for="hardware-action-package-photo">Upload Package Photo</label>
        <input type="file"
               class="form-control"
               id="hardware-action-package-photo"
               name="photo"
               accept="image/*"
               required>
    </x-c360.section-card>
    <x-c360.modal-footer>
        <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn c360-dialog-btn-primary" data-hardware-action-submit>
            Add Package Photo
        </button>
    </x-c360.modal-footer>
</form>
