<?php

use App\Http\Controllers\ApprovalNumberController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardServiceCaseController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuickServiceRequestController;
use App\Http\Controllers\RefundRequestController;
use App\Http\Controllers\RemarkController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\OrderTransactionController;
use App\Http\Controllers\ServiceCaseAssignmentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SettingProductController;
use App\Http\Controllers\SettingSourceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SettingsSectionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/service-cases/{incident}/row', [DashboardServiceCaseController::class, 'row'])
        ->name('dashboard.service-cases.row');
    Route::post('dashboard/transactions/bulk', [OrderTransactionController::class, 'bulkStore'])
        ->name('dashboard.transactions.bulk');
    Route::post('service-requests/quick', [QuickServiceRequestController::class, 'store'])
        ->name('service-requests.quick.store');
    Route::get('/search', [SearchController::class, 'index'])->name('search.index');

    Route::get('orders/lookup', [OrderController::class, 'lookup'])->name('orders.lookup');
    Route::post('orders/{order}/transaction', [OrderTransactionController::class, 'store'])->name('orders.transaction.store');
    Route::delete('orders/{order}/transaction', [OrderTransactionController::class, 'destroy'])->name('orders.transaction.destroy');
    Route::resource('orders', OrderController::class);
    Route::resource('incidents', IncidentController::class);
    Route::patch('incidents/{incident}/assignment', [ServiceCaseAssignmentController::class, 'update'])
        ->name('incidents.assignment.update');

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

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/{notification}', [NotificationController::class, 'show'])->name('notifications.show');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
});

require __DIR__.'/auth.php';
