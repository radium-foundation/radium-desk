<?php

use App\Http\Controllers\ApprovalNumberController;
use App\Http\Controllers\SupportAppointmentController;
use App\Http\Controllers\SupportScheduleRedirectController;
use App\Http\Controllers\AutomationOperationsController;
use App\Http\Controllers\OperationalSystemSettingsController;
use App\Http\Controllers\Customer360Controller;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\CashfreeWebhookLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardLiveController;
use App\Http\Controllers\DashboardServiceCaseController;
use App\Http\Controllers\DashboardWorkspaceActionController;
use App\Http\Controllers\DashboardWorkspaceComponentController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPollController;
use App\Http\Controllers\OperationsDashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\DashboardDeviceModelComponentController;
use App\Http\Controllers\DashboardWorkspaceDeviceModelController;
use App\Http\Controllers\DeviceModelController;
use App\Http\Controllers\OrderDeviceModelController;
use App\Http\Controllers\OrderLegacyVerificationController;
use App\Http\Controllers\OrderSerialController;
use App\Http\Controllers\OrderTransactionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuickServiceRequestController;
use App\Http\Controllers\RefundRequestController;
use App\Http\Controllers\RemarkController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServiceCaseAssignmentController;
use App\Http\Controllers\ServiceCaseStatusController;
use App\Http\Controllers\SettingProductController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SettingSourceController;
use App\Http\Controllers\SettingsSectionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkspaceActionController;
use App\Http\Controllers\WorkspaceComponentController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('support/schedule/{token}', SupportScheduleRedirectController::class)
    ->name('support.schedule.track');

Route::middleware('signed')->group(function () {
    Route::get('support-appointments/{incident}/book', [SupportAppointmentController::class, 'create'])
        ->name('support-appointments.create');
    Route::post('support-appointments/{incident}', [SupportAppointmentController::class, 'store'])
        ->name('support-appointments.store');
    Route::get('support-appointments/{incident}/{appointment}/confirmation', [SupportAppointmentController::class, 'confirmation'])
        ->name('support-appointments.confirmation');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/live', [DashboardLiveController::class, 'refresh'])->name('dashboard.live');
    Route::get('/search', [SearchController::class, 'search'])->name('search.index');
    Route::get('/dashboard/search', [SearchController::class, 'search'])->name('dashboard.search');
    Route::get('dashboard/service-cases/search-rows', [DashboardServiceCaseController::class, 'searchRows'])
        ->name('dashboard.service-cases.search-rows');
    Route::get('dashboard/service-cases/more', [DashboardServiceCaseController::class, 'loadMore'])
        ->name('dashboard.service-cases.load-more');
    Route::get('dashboard/service-cases/{incident}/row', [DashboardServiceCaseController::class, 'row'])
        ->name('dashboard.service-cases.row');
    Route::get('dashboard/service-cases/{incident}/customer-360', [Customer360Controller::class, 'show'])
        ->name('dashboard.service-cases.customer-360');
    Route::post('dashboard/service-cases/{incident}/customer-360/radiumbox-sync', [Customer360Controller::class, 'radiumBoxSync'])
        ->name('dashboard.service-cases.customer-360.radiumbox-sync');
    Route::get('dashboard/service-cases/{incident}/customer-360/device', [Customer360Controller::class, 'device'])
        ->name('dashboard.service-cases.customer-360.device');
    Route::get('dashboard/service-cases/{incident}/customer-360/timeline', [Customer360Controller::class, 'timeline'])
        ->name('dashboard.service-cases.customer-360.timeline');
    Route::get('dashboard/service-cases/{incident}/customer-360/ai-workbench', [Customer360Controller::class, 'aiWorkbench'])
        ->name('dashboard.service-cases.customer-360.ai-workbench');
    Route::post('dashboard/service-cases/{incident}/customer-360/ai-workbench/audit', [Customer360Controller::class, 'auditWorkbench'])
        ->name('dashboard.service-cases.customer-360.ai-workbench.audit');
    Route::post('dashboard/service-cases/{incident}/customer-360/executive-summary/translate', [Customer360Controller::class, 'translateExecutiveSummary'])
        ->name('dashboard.service-cases.customer-360.executive-summary.translate');
    Route::post('dashboard/transactions/bulk', [OrderTransactionController::class, 'bulkStore'])
        ->name('dashboard.transactions.bulk');
    Route::get('dashboard/components/batch-transaction', [DashboardWorkspaceComponentController::class, 'batchTransaction'])
        ->name('dashboard.components.batch-transaction');
    Route::post('dashboard/workspace/batch-transaction', [DashboardWorkspaceActionController::class, 'batchTransaction'])
        ->name('dashboard.workspace.batch-transaction');
    Route::get('dashboard/components/batch-device-model', [DashboardDeviceModelComponentController::class, 'batchAssign'])
        ->name('dashboard.components.batch-device-model');
    Route::post('dashboard/workspace/batch-device-model', [DashboardWorkspaceDeviceModelController::class, 'batchAssign'])
        ->name('dashboard.workspace.batch-device-model');
    Route::post('service-requests/intake/search', [QuickServiceRequestController::class, 'search'])
        ->name('service-requests.intake.search');
    Route::post('service-requests/quick', [QuickServiceRequestController::class, 'store'])
        ->name('service-requests.quick.store');
    Route::get('orders/lookup', [OrderController::class, 'lookup'])->name('orders.lookup');
    Route::get('orders/{order}/service-cases/create', [OrderController::class, 'createServiceCase'])
        ->name('orders.service-cases.create');
    Route::post('orders/{order}/service-cases', [OrderController::class, 'storeServiceCase'])
        ->name('orders.service-cases.store');
    Route::post('orders/{order}/transaction', [OrderTransactionController::class, 'store'])->name('orders.transaction.store');
    Route::post('orders/{order}/legacy-verification', [OrderLegacyVerificationController::class, 'store'])
        ->name('orders.legacy-verification.store');
    Route::delete('orders/{order}/transaction', [OrderTransactionController::class, 'destroy'])->name('orders.transaction.destroy');
    Route::post('orders/{order}/serial', [OrderSerialController::class, 'store'])->name('orders.serial.store');
    Route::post('orders/{order}/device-model', [OrderDeviceModelController::class, 'store'])->name('orders.device-model.store');
    Route::resource('orders', OrderController::class);
    Route::resource('incidents', IncidentController::class);
    Route::patch('incidents/{incident}/assignment', [ServiceCaseAssignmentController::class, 'update'])
        ->name('incidents.assignment.update');
    Route::patch('incidents/{incident}/status', [ServiceCaseStatusController::class, 'update'])
        ->name('incidents.status.update');
    Route::get('incidents/{incident}/components/{component}', [WorkspaceComponentController::class, 'show'])
        ->name('incidents.components.show');
    Route::patch('incidents/{incident}/workspace/action', [WorkspaceActionController::class, 'action'])
        ->name('incidents.workspace.action');
    Route::patch('incidents/{incident}/workspace/assign', [WorkspaceActionController::class, 'assign'])
        ->name('incidents.workspace.assign');
    Route::post('incidents/{incident}/workspace/remark', [WorkspaceActionController::class, 'remark'])
        ->name('incidents.workspace.remark');
    Route::post('incidents/{incident}/workspace/request-serial', [WorkspaceActionController::class, 'requestSerial'])
        ->name('incidents.workspace.request-serial');
    Route::patch('incidents/{incident}/workspace/resolve', [WorkspaceActionController::class, 'resolve'])
        ->name('incidents.workspace.resolve');
    Route::patch('incidents/{incident}/workspace/close', [WorkspaceActionController::class, 'close'])
        ->name('incidents.workspace.close');

    Route::post('remarks', [RemarkController::class, 'store'])->name('remarks.store');
    Route::delete('remarks/{remark}', [RemarkController::class, 'destroy'])->name('remarks.destroy');

    Route::get('refunds/incidents/lookup', [RefundRequestController::class, 'lookupIncidents'])
        ->name('refunds.incidents.lookup');
    Route::post('refunds/{refund}/approve', [RefundRequestController::class, 'approve'])->name('refunds.approve');
    Route::post('refunds/{refund}/reject', [RefundRequestController::class, 'reject'])->name('refunds.reject');
    Route::resource('refunds', RefundRequestController::class)->except(['edit', 'update']);

    Route::get('approvals/{approval}/incidents/lookup', [ApprovalNumberController::class, 'lookupIncidents'])
        ->name('approvals.incidents.lookup');
    Route::post('approvals/{approval}/incidents', [ApprovalNumberController::class, 'linkIncidents'])
        ->name('approvals.incidents.link');
    Route::delete('approvals/{approval}/incidents/{incident}', [ApprovalNumberController::class, 'unlinkIncident'])
        ->name('approvals.incidents.unlink');
    Route::resource('approvals', ApprovalNumberController::class)->except(['edit', 'update']);

    Route::resource('audit-logs', AuditLogController::class)
        ->only(['index', 'show'])
        ->parameters(['audit-logs' => 'auditLog']);

    Route::get('/admin/automation', [AutomationOperationsController::class, 'index'])
        ->name('admin.automation.index');

    Route::get('/admin/operations', [OperationsDashboardController::class, 'index'])
        ->name('admin.operations.index');
    Route::get('/admin/operations/live', [OperationsDashboardController::class, 'live'])
        ->name('admin.operations.live');
    Route::post('/admin/operations/radiumbox/batch-recover', [OperationsDashboardController::class, 'batchRecoverRadiumBox'])
        ->name('admin.operations.radiumbox.batch-recover');

    Route::get('/admin/system-settings', [OperationalSystemSettingsController::class, 'index'])
        ->name('admin.system-settings.index');
    Route::put('/admin/system-settings', [OperationalSystemSettingsController::class, 'update'])
        ->name('admin.system-settings.update');

    Route::prefix('cashfree')->name('cashfree.')->group(function () {
        Route::get('webhook-explorer', [CashfreeWebhookLogController::class, 'index'])
            ->name('webhook-explorer.index');
        Route::get('webhook-explorer/{cashfreeWebhookLog}', [CashfreeWebhookLogController::class, 'show'])
            ->name('webhook-explorer.show');
    });

    Route::resource('users', UserController::class)->except(['show']);
    Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status.update');
    Route::patch('users/{user}/password-reset', [UserController::class, 'resetPassword'])->name('users.password-reset.update');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings/general', [SettingsSectionController::class, 'updateGeneral'])->name('settings.general.update');
    Route::put('/settings/assignment', [SettingsSectionController::class, 'updateAssignment'])->name('settings.assignment.update');
    Route::put('/settings/notifications', [SettingsSectionController::class, 'updateNotifications'])->name('settings.notifications.update');
    Route::put('/settings/sla', [SettingsSectionController::class, 'updateSla'])->name('settings.sla.update');
    Route::put('/settings/search', [SettingsSectionController::class, 'updateSearch'])->name('settings.search.update');
    Route::post('/settings/products', [SettingProductController::class, 'store'])->name('settings.products.store');
    Route::put('/settings/products/{product}', [SettingProductController::class, 'update'])->name('settings.products.update');
    Route::patch('/settings/products/{product}/toggle', [SettingProductController::class, 'toggle'])->name('settings.products.toggle');
    Route::post('/settings/sources', [SettingSourceController::class, 'store'])->name('settings.sources.store');
    Route::put('/settings/sources/{source}', [SettingSourceController::class, 'update'])->name('settings.sources.update');
    Route::patch('/settings/sources/{source}/toggle', [SettingSourceController::class, 'toggle'])->name('settings.sources.toggle');
    Route::post('/settings/device-models', [DeviceModelController::class, 'store'])->name('settings.device-models.store');
    Route::put('/settings/device-models/{deviceModel}', [DeviceModelController::class, 'update'])->name('settings.device-models.update');
    Route::patch('/settings/device-models/{deviceModel}/toggle', [DeviceModelController::class, 'toggle'])->name('settings.device-models.toggle');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/poll', [NotificationPollController::class, 'poll'])->name('notifications.poll');
    Route::get('/notifications/{notification}', [NotificationController::class, 'show'])->name('notifications.show');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
});

require __DIR__.'/auth.php';
