@if(session('status'))
    @php
        $toastMessage = match (session('status')) {
            'profile-updated' => 'Profile updated successfully.',
            'password-updated' => 'Password updated successfully.',
            'order-created' => 'Order created successfully.',
            'order-updated' => 'Order updated successfully.',
            'order-deleted' => 'Order deleted successfully.',
            'incident-created' => 'Service case created successfully.',
            'incident-updated' => 'Incident updated successfully.',
            'incident-deleted' => 'Incident deleted successfully.',
            'remark-created' => 'Note added successfully.',
            'remark-deleted' => 'Note deleted successfully.',
            'approval-created' => 'Approval number created successfully.',
            'approval-deleted' => 'Approval number deleted successfully.',
            'approval-incidents-linked' => 'Incident(s) linked successfully.',
            'approval-incidents-already-linked' => 'Selected incident(s) are already linked to this approval number.',
            'approval-incident-unlinked' => 'Incident unlinked successfully.',
            'refund-created' => 'Refund request created successfully.',
            'refund-approved' => 'Refund request approved successfully.',
            'refund-rejected' => 'Refund request rejected successfully.',
            'refund-deleted' => 'Refund request deleted successfully.',
            default => session('status'),
        };
    @endphp

    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <div id="appSuccessToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true" data-toast-show>
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-check-circle me-1"></i> {{ $toastMessage }}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    </div>
@endif

@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show py-2 mb-3" role="alert">
        <ul class="mb-0 ps-3 small">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif
