<?php

use App\Http\Controllers\ApprovalNumberController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuickServiceRequestController;
use App\Http\Controllers\RefundRequestController;
use App\Http\Controllers\RemarkController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('service-requests/quick', [QuickServiceRequestController::class, 'store'])
        ->name('service-requests.quick.store');
    Route::get('/search', [SearchController::class, 'index'])->name('search.index');

    Route::get('orders/lookup', [OrderController::class, 'lookup'])->name('orders.lookup');
    Route::resource('orders', OrderController::class);
    Route::resource('incidents', IncidentController::class);

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

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
});

require __DIR__.'/auth.php';
