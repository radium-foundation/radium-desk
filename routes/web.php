<?php

use App\Http\Controllers\AdministrationHomeController;
use App\Http\Controllers\GmailAdminActionsController;
use App\Http\Controllers\ApprovalNumberController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\AutomationHealthController;
use App\Http\Controllers\AutomationOperationsController;
use App\Http\Controllers\BonvoiceClickToCallController;
use App\Http\Controllers\CashBook\CashBookController;
use App\Http\Controllers\CashfreeWebhookLogController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\CompanyHolidayController;
use App\Http\Controllers\ConversationWorkspaceController;
use App\Http\Controllers\Customer360Controller;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardActivityController;
use App\Http\Controllers\DashboardTeamActivityController;
use App\Http\Controllers\DashboardDeviceModelComponentController;
use App\Http\Controllers\DashboardLiveController;
use App\Http\Controllers\DashboardServiceCaseController;
use App\Http\Controllers\DashboardWorkspaceActionController;
use App\Http\Controllers\DashboardWorkspaceComponentController;
use App\Http\Controllers\DashboardWorkspaceDeviceModelController;
use App\Http\Controllers\DeviceModelAliasController;
use App\Http\Controllers\DeviceModelController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IncomingEmailContentController;
use App\Http\Controllers\IraOperationsBrainController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\MyPerformanceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPollController;
use App\Http\Controllers\OperationalSystemSettingsController;
use App\Http\Controllers\RealtimeAdminActionsController;
use App\Http\Controllers\RealtimeConnectionStatusController;
use App\Http\Controllers\OperationsDashboardController;
use App\Http\Controllers\PlatformDashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderDeviceModelController;
use App\Http\Controllers\OrderLegacyVerificationController;
use App\Http\Controllers\OrderSerialController;
use App\Http\Controllers\OrderTransactionController;
use App\Http\Controllers\PresenceHeartbeatController;
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
use App\Http\Controllers\SupportAppointmentController;
use App\Http\Controllers\SupportScheduleRedirectController;
use App\Http\Controllers\TeamAvailabilityController;
use App\Http\Controllers\TeamPerformanceController;
use App\Http\Controllers\Workforce\EmployeeSalaryController;
use App\Http\Controllers\Workforce\MonthlyAttendanceController;
use App\Http\Controllers\Workforce\PayrollController;
use App\Http\Controllers\Workforce\WorkRecognitionController;
use App\Http\Controllers\Workforce\WorkforceMember360Controller;
use App\Http\Controllers\Finance\BankAccountController;
use App\Http\Controllers\Finance\BankLedgerController;
use App\Http\Controllers\Finance\CashAccountController;
use App\Http\Controllers\Finance\CashLedgerController;
use App\Http\Controllers\Finance\CustomerPaymentController;
use App\Http\Controllers\Finance\DailyClosingController;
use App\Http\Controllers\Finance\DashboardController as FinanceDashboardController;
use App\Http\Controllers\Finance\ExpenseCategoryController;
use App\Http\Controllers\Finance\ExpenseController as FinanceExpenseController;
use App\Http\Controllers\Finance\PaymentMethodController;
use App\Http\Controllers\Finance\SettingsController as FinanceSettingsController;
use App\Http\Controllers\Finance\VendorPaymentController;
use App\Http\Controllers\TeamTelegramBroadcastController;
use App\Http\Controllers\TeamWorkScheduleController;
use App\Http\Controllers\Workforce360Controller;
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
    Route::get('/dashboard/live/rows', [DashboardLiveController::class, 'rows'])->name('dashboard.live.rows');
    Route::get('/dashboard/activity', [DashboardActivityController::class, 'refresh'])->name('dashboard.activity');
    Route::get('/dashboard/team-activity', [DashboardTeamActivityController::class, 'refresh'])->name('dashboard.team-activity');
    Route::post('/dashboard/realtime/connection-status', RealtimeConnectionStatusController::class)
        ->name('dashboard.realtime.connection-status');
    Route::get('/search', [SearchController::class, 'search'])->name('search.index');
    Route::get('dashboard/service-cases/search-rows', [DashboardServiceCaseController::class, 'searchRows'])
        ->name('dashboard.service-cases.search-rows');
    Route::get('dashboard/service-cases/more', [DashboardServiceCaseController::class, 'loadMore'])
        ->name('dashboard.service-cases.load-more');
    Route::get('dashboard/service-cases/{incident}/row', [DashboardServiceCaseController::class, 'row'])
        ->name('dashboard.service-cases.row');
    Route::get('dashboard/service-cases/{incident}/customer-360', [Customer360Controller::class, 'show'])
        ->name('dashboard.service-cases.customer-360');
    Route::patch('dashboard/service-cases/{incident}/conversation-workspace', [ConversationWorkspaceController::class, 'update'])
        ->name('dashboard.service-cases.conversation-workspace.update');
    Route::get('dashboard/orders/{order}/customer-360', [Customer360Controller::class, 'showForOrder'])
        ->name('dashboard.orders.customer-360');
    Route::post('bonvoice/click-to-call', BonvoiceClickToCallController::class)
        ->name('bonvoice.click-to-call');
    Route::post('dashboard/service-cases/{incident}/customer-360/radiumbox-sync', [Customer360Controller::class, 'radiumBoxSync'])
        ->name('dashboard.service-cases.customer-360.radiumbox-sync');
    Route::get('dashboard/service-cases/{incident}/customer-360/device', [Customer360Controller::class, 'device'])
        ->name('dashboard.service-cases.customer-360.device');
    Route::get('dashboard/service-cases/{incident}/customer-360/timeline', [Customer360Controller::class, 'timeline'])
        ->name('dashboard.service-cases.customer-360.timeline');
    Route::get('dashboard/service-cases/{incident}/customer-360/ai-workbench', [Customer360Controller::class, 'aiWorkbench'])
        ->name('dashboard.service-cases.customer-360.ai-workbench');
    Route::get('dashboard/service-cases/{incident}/customer-360/executive-summary', [Customer360Controller::class, 'executiveSummary'])
        ->name('dashboard.service-cases.customer-360.executive-summary');
    Route::post('dashboard/service-cases/{incident}/customer-360/ai-workbench/audit', [Customer360Controller::class, 'auditWorkbench'])
        ->name('dashboard.service-cases.customer-360.ai-workbench.audit');
    Route::post('dashboard/service-cases/{incident}/customer-360/executive-summary/translate', [Customer360Controller::class, 'translateExecutiveSummary'])
        ->name('dashboard.service-cases.customer-360.executive-summary.translate');
    Route::get('dashboard/incoming-email-messages/{incomingEmailMessage}/content', [IncomingEmailContentController::class, 'show'])
        ->name('dashboard.incoming-email-messages.content');
    Route::get('dashboard/incoming-email-messages/{incomingEmailMessage}/reply-context', [IncomingEmailContentController::class, 'replyContext'])
        ->name('dashboard.incoming-email-messages.reply-context');
    Route::post('dashboard/incoming-email-messages/{incomingEmailMessage}/reply-preview', [IncomingEmailContentController::class, 'replyPreview'])
        ->name('dashboard.incoming-email-messages.reply-preview');
    Route::post('dashboard/incoming-email-messages/{incomingEmailMessage}/reply', [IncomingEmailContentController::class, 'reply'])
        ->name('dashboard.incoming-email-messages.reply');
    Route::get('dashboard/incoming-email-messages/{incomingEmailMessage}/attachments/{attachment}', [IncomingEmailContentController::class, 'downloadAttachment'])
        ->name('dashboard.incoming-email-messages.attachments.download');
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
    Route::post('incidents/{incident}/workspace/request-correct-serial', [WorkspaceActionController::class, 'requestCorrectSerial'])
        ->name('incidents.workspace.request-correct-serial');
    Route::post('incidents/{incident}/workspace/customer-not-responding', [WorkspaceActionController::class, 'customerNotResponding'])
        ->name('incidents.workspace.customer-not-responding');
    Route::post('incidents/{incident}/workspace/communication-actions/{key}', [WorkspaceActionController::class, 'communicationAction'])
        ->name('incidents.workspace.communication-action');
    Route::patch('incidents/{incident}/workspace/link-order', [WorkspaceActionController::class, 'linkOrder'])
        ->name('incidents.workspace.link-order');
    Route::post('incidents/{incident}/workspace/refund-request', [WorkspaceActionController::class, 'refundRequest'])
        ->name('incidents.workspace.refund-request');
    Route::patch('incidents/{incident}/workspace/correct-customer-details', [WorkspaceActionController::class, 'correctCustomerDetails'])
        ->name('incidents.workspace.correct-customer-details');
    Route::patch('incidents/{incident}/workspace/correct-serial-number', [WorkspaceActionController::class, 'correctSerialNumber'])
        ->name('incidents.workspace.correct-serial-number');
    Route::post('incidents/{incident}/workspace/correct-serial-number/validate', [WorkspaceActionController::class, 'validateCorrectSerialNumber'])
        ->name('incidents.workspace.correct-serial-number.validate');
    Route::patch('incidents/{incident}/workspace/correct-device-model', [WorkspaceActionController::class, 'correctDeviceModel'])
        ->name('incidents.workspace.correct-device-model');
    Route::patch('incidents/{incident}/workspace/correct-device-identity', [WorkspaceActionController::class, 'correctDeviceIdentity'])
        ->name('incidents.workspace.correct-device-identity');
    Route::post('incidents/{incident}/workspace/correct-device-identity/validate', [WorkspaceActionController::class, 'validateCorrectDeviceIdentity'])
        ->name('incidents.workspace.correct-device-identity.validate');
    Route::patch('incidents/{incident}/workspace/resolve', [WorkspaceActionController::class, 'resolve'])
        ->name('incidents.workspace.resolve');
    Route::patch('incidents/{incident}/workspace/close', [WorkspaceActionController::class, 'close'])
        ->name('incidents.workspace.close');

    Route::post('remarks', [RemarkController::class, 'store'])->name('remarks.store');
    Route::delete('remarks/{remark}', [RemarkController::class, 'destroy'])->name('remarks.destroy');

    Route::get('refunds/incidents/lookup', [RefundRequestController::class, 'lookupIncidents'])
        ->name('refunds.incidents.lookup');
    Route::get('refunds/calculation-preview', [RefundRequestController::class, 'calculationPreview'])
        ->name('refunds.calculation-preview');
    Route::post('refunds/{refund}/approve', [RefundRequestController::class, 'approve'])->name('refunds.approve');
    Route::post('refunds/{refund}/reject', [RefundRequestController::class, 'reject'])->name('refunds.reject');
    Route::post('refunds/{refund}/complete', [RefundRequestController::class, 'complete'])->name('refunds.complete');
    Route::resource('refunds', RefundRequestController::class)->except(['edit', 'update']);

    Route::prefix('cash-book')->name('cash-book.')->group(function () {
        Route::get('/', [CashBookController::class, 'index'])->name('index');
        Route::get('/create', [CashBookController::class, 'create'])->name('create');
        Route::post('/', [CashBookController::class, 'store'])->name('store');
        Route::get('/historical/create', [CashBookController::class, 'historicalCreate'])->name('historical.create');
        Route::post('/historical', [CashBookController::class, 'historicalStore'])->name('historical.store');
        Route::get('/{cashBookEntry}/edit-warning', [CashBookController::class, 'editWarning'])->name('edit-warning');
        Route::post('/{cashBookEntry}/edit-acknowledge', [CashBookController::class, 'acknowledgeEdit'])->name('edit-acknowledge');
        Route::get('/{cashBookEntry}/edit', [CashBookController::class, 'edit'])->name('edit');
        Route::put('/{cashBookEntry}', [CashBookController::class, 'update'])->name('update');
        Route::get('/{cashBookEntry}/delete-warning', [CashBookController::class, 'deleteWarning'])->name('delete-warning');
        Route::delete('/{cashBookEntry}', [CashBookController::class, 'destroy'])->name('destroy');
    });

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

    Route::get('/admin/operations/automation-health', [AutomationHealthController::class, 'index'])
        ->name('admin.operations.automation-health');
    Route::get('/admin/operations/automation-health/executions/{execution}', [AutomationHealthController::class, 'show'])
        ->name('admin.operations.automation-health.executions.show');

    Route::get('/admin/platform', [PlatformDashboardController::class, 'index'])
        ->name('admin.platform.index');
    Route::get('/admin/platform/cards/{card}', [PlatformDashboardController::class, 'showCard'])
        ->name('admin.platform.cards.show');
    Route::get('/admin/platform/zones/{zone}', [PlatformDashboardController::class, 'zone'])
        ->name('admin.platform.zones.show');
    Route::get('/admin/platform/zones/{zone}/expand/{item}', [PlatformDashboardController::class, 'expand'])
        ->name('admin.platform.zones.expand');

    Route::get('/admin/operations', [OperationsDashboardController::class, 'index'])
        ->name('admin.operations.index');
    Route::get('/admin/operations/live', [OperationsDashboardController::class, 'live'])
        ->name('admin.operations.live');
    Route::post('/admin/operations/ira/feedback', [IraOperationsBrainController::class, 'feedback'])
        ->name('admin.operations.ira.feedback');
    Route::post('/admin/operations/radiumbox/batch-recover', [OperationsDashboardController::class, 'batchRecoverRadiumBox'])
        ->name('admin.operations.radiumbox.batch-recover');
    Route::post('/admin/operations/telegram/broadcast', [TeamTelegramBroadcastController::class, 'store'])
        ->name('admin.operations.telegram.broadcast');

    Route::resource('leave-requests', LeaveRequestController::class)
        ->except(['edit', 'update', 'destroy'])
        ->parameters(['leave-requests' => 'leaveRequest']);
    Route::post('leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])
        ->name('leave-requests.approve');
    Route::post('leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])
        ->name('leave-requests.reject');

    Route::prefix('admin/workforce')->name('admin.workforce.')->group(function () {
        Route::get('holidays', [CompanyHolidayController::class, 'index'])->name('holidays.index');
        Route::post('holidays', [CompanyHolidayController::class, 'store'])->name('holidays.store');
        Route::delete('holidays/{holiday}', [CompanyHolidayController::class, 'destroy'])->name('holidays.destroy');
        Route::get('performance', [TeamPerformanceController::class, 'index'])->name('performance.index');
    });

    Route::prefix('workforce-management')->name('workforce-management.')->group(function () {
        Route::redirect('/', '/workforce-management/attendance');
        Route::get('attendance', [MonthlyAttendanceController::class, 'index'])->name('attendance.index');
        Route::post('attendance/payroll-lock', [MonthlyAttendanceController::class, 'lock'])->name('attendance.payroll-lock');
        Route::post('attendance/payroll-unlock', [MonthlyAttendanceController::class, 'unlock'])->name('attendance.payroll-unlock');
        Route::get('payroll', [PayrollController::class, 'index'])->name('payroll.index');
        Route::post('payroll/finalize', [PayrollController::class, 'finalize'])->name('payroll.finalize');
        Route::get('payroll/members/{user}', [PayrollController::class, 'show'])->name('payroll.show');
        Route::get('salaries', [EmployeeSalaryController::class, 'index'])->name('salaries.index');
        Route::post('salaries', [EmployeeSalaryController::class, 'store'])->name('salaries.store');
        Route::post('salaries/{salary}/revisions', [EmployeeSalaryController::class, 'revise'])->name('salaries.revise');
        Route::get('recognition', [WorkRecognitionController::class, 'index'])->name('recognition.index');
        Route::post('recognition/scan', [WorkRecognitionController::class, 'scan'])->name('recognition.scan');
        Route::get('recognition/{review}', [WorkRecognitionController::class, 'show'])->name('recognition.show');
        Route::post('recognition/{review}/decide', [WorkRecognitionController::class, 'decide'])->name('recognition.decide');
        Route::post('recognition/{review}/refresh', [WorkRecognitionController::class, 'refresh'])->name('recognition.refresh');
        Route::get('members/{user}', [WorkforceMember360Controller::class, 'show'])->name('members.show');
    });

    Route::prefix('finance')->name('finance.')->group(function () {
        Route::redirect('/', '/finance/dashboard');
        Route::get('dashboard', FinanceDashboardController::class)->name('dashboard');
        Route::get('payments', [CustomerPaymentController::class, 'index'])->name('payments.index');
        Route::get('expenses', [FinanceExpenseController::class, 'index'])->name('expenses.index');
        Route::get('expenses/create', [FinanceExpenseController::class, 'create'])->name('expenses.create');
        Route::post('expenses', [FinanceExpenseController::class, 'store'])->name('expenses.store');
        Route::get('expenses/{expense}', [FinanceExpenseController::class, 'show'])->name('expenses.show');
        Route::get('expenses/{expense}/edit', [FinanceExpenseController::class, 'edit'])->name('expenses.edit');
        Route::put('expenses/{expense}', [FinanceExpenseController::class, 'update'])->name('expenses.update');
        Route::post('expenses/{expense}/post', [FinanceExpenseController::class, 'post'])->name('expenses.post');
        Route::get('cash', [CashLedgerController::class, 'index'])->name('cash.index');
        Route::get('closings', [DailyClosingController::class, 'index'])->name('closings.index');
        Route::get('bank', [BankLedgerController::class, 'index'])->name('bank.index');
        Route::get('vendor-payments', [VendorPaymentController::class, 'index'])->name('vendor-payments.index');

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::redirect('/', '/finance/settings/cash-accounts');
            Route::get('cash-accounts', [FinanceSettingsController::class, 'cashAccounts'])->name('cash-accounts');
            Route::post('cash-accounts', [CashAccountController::class, 'store'])->name('cash-accounts.store');
            Route::put('cash-accounts/{cashAccount}', [CashAccountController::class, 'update'])->name('cash-accounts.update');
            Route::patch('cash-accounts/{cashAccount}/toggle', [CashAccountController::class, 'toggle'])->name('cash-accounts.toggle');

            Route::get('bank-accounts', [FinanceSettingsController::class, 'bankAccounts'])->name('bank-accounts');
            Route::post('bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
            Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
            Route::patch('bank-accounts/{bankAccount}/toggle', [BankAccountController::class, 'toggle'])->name('bank-accounts.toggle');

            Route::get('payment-methods', [FinanceSettingsController::class, 'paymentMethods'])->name('payment-methods');
            Route::post('payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
            Route::put('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update'])->name('payment-methods.update');
            Route::patch('payment-methods/{paymentMethod}/toggle', [PaymentMethodController::class, 'toggle'])->name('payment-methods.toggle');

            Route::get('expense-categories', [FinanceSettingsController::class, 'expenseCategories'])->name('expense-categories');
            Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
            Route::put('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
            Route::patch('expense-categories/{expenseCategory}/toggle', [ExpenseCategoryController::class, 'toggle'])->name('expense-categories.toggle');

            Route::get('vendor-master', [FinanceSettingsController::class, 'vendorMaster'])->name('vendor-master');
            Route::get('chart-of-accounts', [FinanceSettingsController::class, 'chartOfAccounts'])->name('chart-of-accounts');
            Route::post('chart-of-accounts', [FinanceSettingsController::class, 'storeAccount'])->name('chart-of-accounts.store');
            Route::patch('chart-of-accounts/{account}/toggle', [FinanceSettingsController::class, 'toggleAccount'])->name('chart-of-accounts.toggle');
            Route::get('financial-preferences', [FinanceSettingsController::class, 'financialPreferences'])->name('financial-preferences');
            Route::put('financial-preferences', [FinanceSettingsController::class, 'updateFinancialPreferences'])->name('financial-preferences.update');
            Route::get('opening-balances', [FinanceSettingsController::class, 'openingBalances'])->name('opening-balances');
            Route::post('opening-balances', [FinanceSettingsController::class, 'storeOpeningBalance'])->name('opening-balances.store');
            Route::get('journals', [FinanceSettingsController::class, 'journals'])->name('journals');
            Route::get('journals/{journal}', [FinanceSettingsController::class, 'showJournal'])->name('journals.show');
        });
    });

    Route::get('/my-performance', [MyPerformanceController::class, 'index'])->name('my-performance.index');

    Route::get('/workforce', [Workforce360Controller::class, 'index'])->name('workforce.index');
    Route::get('/workforce/{user}', [Workforce360Controller::class, 'show'])->name('workforce.show');
    Route::get('/my-workforce', [Workforce360Controller::class, 'my'])->name('my-workforce.index');

    Route::put('users/{user}/work-schedule', [TeamWorkScheduleController::class, 'update'])
        ->name('users.work-schedule.update');

    Route::get('/admin/administration', AdministrationHomeController::class)
        ->name('admin.administration.index');

    Route::post('/admin/gmail/sync-now', [GmailAdminActionsController::class, 'syncNow'])
        ->name('admin.gmail.sync-now');
    Route::post('/admin/gmail/rebaseline', [GmailAdminActionsController::class, 'rebaseline'])
        ->name('admin.gmail.rebaseline');
    Route::get('/admin/gmail/logs', [GmailAdminActionsController::class, 'logs'])
        ->name('admin.gmail.logs');
    Route::get('/admin/gmail/failed-messages', [GmailAdminActionsController::class, 'failedMessages'])
        ->name('admin.gmail.failed-messages');

    Route::get('/admin/system-settings', [OperationalSystemSettingsController::class, 'index'])
        ->name('admin.system-settings.index');
    Route::get('/admin/platform-configuration', [OperationalSystemSettingsController::class, 'platformConfiguration'])
        ->name('admin.platform-configuration.index');
    Route::put('/admin/system-settings', [OperationalSystemSettingsController::class, 'update'])
        ->name('admin.system-settings.update');
    Route::post('/admin/system-settings/realtime/test', [RealtimeAdminActionsController::class, 'test'])
        ->name('admin.system-settings.realtime.test');
    Route::post('/admin/system-settings/realtime/force-reconnect', [RealtimeAdminActionsController::class, 'forceReconnect'])
        ->name('admin.system-settings.realtime.force-reconnect');
    Route::post('/admin/system-settings/realtime/reset-status', [RealtimeAdminActionsController::class, 'resetStatus'])
        ->name('admin.system-settings.realtime.reset-status');

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
    Route::post('/settings/device-model-aliases', [DeviceModelAliasController::class, 'store'])->name('settings.device-model-aliases.store');
    Route::put('/settings/device-model-aliases/{deviceModelAlias}', [DeviceModelAliasController::class, 'update'])->name('settings.device-model-aliases.update');
    Route::delete('/settings/device-model-aliases/{deviceModelAlias}', [DeviceModelAliasController::class, 'destroy'])->name('settings.device-model-aliases.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/telegram', [ProfileController::class, 'updateTelegram'])->name('profile.telegram.update');
    Route::patch('/profile/availability', [TeamAvailabilityController::class, 'update'])->name('profile.availability.update');
    Route::post('/presence/heartbeat', [PresenceHeartbeatController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('presence.heartbeat');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/poll', [NotificationPollController::class, 'poll'])->name('notifications.poll');
    Route::get('/notifications/{notification}', [NotificationController::class, 'show'])->name('notifications.show');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');

    Route::get('/changelog', ChangelogController::class)->name('changelog.index');
});

require __DIR__.'/auth.php';
