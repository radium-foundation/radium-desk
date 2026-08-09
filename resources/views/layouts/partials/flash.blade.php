@if(session('status'))
    @php
        $toastMessage = match (session('status')) {
            'profile-updated' => 'Profile updated successfully.',
            'password-updated' => 'Password updated successfully.',
            'order-created' => 'Order created successfully.',
            'order-updated' => 'Order updated successfully.',
            'order-deleted' => 'Order deleted successfully.',
            'order-transaction-assigned' => 'Service reference saved. Order marked as completed.',
            'order-transaction-unlocked' => 'Order unlocked successfully.',
            'order-found' => config('ui.service_case.order_found_message'),
            'service-case-created' => 'Service Case '.session('service_case_reference').' created successfully.',
            'service-case-reassigned' => 'Service case owner updated successfully.',
            'service-case-resolved' => 'Service case marked as resolved.',
            'service-case-closed' => 'Service case closed successfully.',
            'service-case-status-updated' => 'Service case status updated successfully.',
            'notification-read' => 'Notification marked as read.',
            'notifications-read-all' => 'All notifications marked as read.',
            'incident-created' => 'Service case created successfully.',
            'incident-updated' => 'Service case updated successfully.',
            'incident-deleted' => 'Service case deleted successfully.',
            'remark-created' => 'Note added successfully.',
            'remark-deleted' => 'Note deleted successfully.',
            'approval-created' => 'Approval number created successfully.',
            'approval-deleted' => 'Approval number deleted successfully.',
            'approval-incidents-linked' => 'Service case(s) linked successfully.',
            'approval-incidents-already-linked' => 'Selected service case(s) are already linked to this approval number.',
            'approval-incident-unlinked' => 'Service case unlinked successfully.',
            'refund-created' => 'Refund request created successfully.',
            'refund-approved' => 'Refund approved and moved to Pending Execution.',
            'refund-rejected' => 'Refund request rejected successfully.',
            'refund-completed' => 'Refund marked completed. Notifications and case close were triggered.',
            'refund-deleted' => 'Refund request deleted successfully.',
            'user-created' => 'User created successfully.',
            'user-updated' => 'User updated successfully.',
            'user-activated' => 'User activated successfully.',
            'user-deactivated' => 'User deactivated successfully.',
            'user-password-reset' => 'Password reset successfully.',
            'user-deleted' => 'User deleted successfully.',
            'settings-general-updated' => 'General settings saved successfully.',
            'settings-assignment-updated' => 'Assignment settings saved successfully.',
            'settings-notifications-updated' => 'Notification settings saved successfully.',
            'settings-sla-updated' => 'SLA settings saved successfully.',
            'settings-search-updated' => 'Search settings saved successfully.',
            'settings-product-created' => 'Product added successfully.',
            'settings-product-updated' => 'Product updated successfully.',
            'settings-product-enabled' => 'Product enabled successfully.',
            'settings-product-disabled' => 'Product disabled successfully.',
            'settings-source-created' => 'Source added successfully.',
            'settings-source-updated' => 'Source updated successfully.',
            'settings-source-enabled' => 'Source enabled successfully.',
            'settings-source-disabled' => 'Source disabled successfully.',
            'device-model-created' => 'Model added successfully.',
            'device-model-updated' => 'Model updated successfully.',
            'device-model-activated' => 'Model activated successfully.',
            'device-model-deactivated' => 'Model deactivated successfully.',
            'operational-system-settings-updated' => 'System settings saved successfully.',
            'payroll-month-locked' => 'Payroll month locked successfully.',
            'payroll-month-unlocked' => 'Payroll month unlocked successfully.',
            'payroll-month-finalized' => 'Payroll finalized. Values are frozen for this month.',
            'employee-salary-created' => 'Employee salary revision created successfully.',
            'employee-salary-updated' => 'Employee salary updated successfully.',
            'employee-salary-revised' => 'New salary revision created. Prior revision preserved.',
            'work-recognition-decided' => 'Work Recognition decision saved.',
            'work-recognition-refreshed' => 'Recognition evidence refreshed.',
            'work-recognition-scan-queued' => 'Work Recognition month scan has been queued.',
            'work-recognition-scanned' => 'Work Recognition scan completed'
                .(session('work_recognition_scanned_count') !== null
                    ? ' ('.session('work_recognition_scanned_count').' reviews touched).'
                    : '.'),
            'todo-created' => 'To-do created successfully.',
            'todo-updated' => 'To-do updated successfully.',
            'todo-assigned' => 'To-do assignee updated successfully.',
            'todo-completed' => 'To-do marked complete.',
            'todo-reopened' => 'To-do reopened.',
            'todo-cancelled' => 'To-do cancelled.',
            'todo-deleted' => 'To-do deleted.',
            'finance-payment-method-created' => 'Payment method added successfully.',
            'finance-payment-method-updated' => 'Payment method updated successfully.',
            'finance-payment-method-activated' => 'Payment method activated successfully.',
            'finance-payment-method-deactivated' => 'Payment method deactivated successfully.',
            'finance-expense-category-created' => 'Expense category added successfully.',
            'finance-expense-category-updated' => 'Expense category updated successfully.',
            'finance-expense-category-activated' => 'Expense category activated successfully.',
            'finance-expense-category-deactivated' => 'Expense category deactivated successfully.',
            'finance-cash-account-created' => 'Cash account added successfully.',
            'finance-cash-account-updated' => 'Cash account updated successfully.',
            'finance-cash-account-activated' => 'Cash account activated successfully.',
            'finance-cash-account-deactivated' => 'Cash account deactivated successfully.',
            'finance-bank-account-created' => 'Bank account added successfully.',
            'finance-bank-account-updated' => 'Bank account updated successfully.',
            'finance-bank-account-activated' => 'Bank account activated successfully.',
            'finance-bank-account-deactivated' => 'Bank account deactivated successfully.',
            'finance-expense-created' => 'Expense draft saved successfully.',
            'finance-expense-updated' => 'Expense draft updated successfully.',
            'finance-expense-posted' => 'Expense posted successfully. It can no longer be edited.',
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
