<?php

declare(strict_types=1);

use App\Http\Controllers\LocaleSwitchController;
use App\Http\Controllers\Tenant\AdminWebPushSubscriptionController;
use App\Http\Controllers\Tenant\ContributionImportSampleController;
use App\Http\Controllers\Tenant\DatabaseBackupDownloadController;
use App\Http\Controllers\Tenant\DirectMessageAttachmentController;
use App\Http\Controllers\Tenant\FiscalCloseExportDownloadController;
use App\Http\Controllers\Tenant\LegacyLoanImportSampleController;
use App\Http\Controllers\Tenant\LegacyMemberImportSampleController;
use App\Http\Controllers\Tenant\LegacyPaymentClassifiedDownloadController;
use App\Http\Controllers\Tenant\LegacyPaymentImportSampleController;
use App\Http\Controllers\Tenant\LegacyStoredClassifiedPaymentsDownloadController;
use App\Http\Controllers\Tenant\LoanImportSampleController;
use App\Http\Controllers\Tenant\LoanRepaymentImportSampleController;
use App\Http\Controllers\Tenant\LoanSchedulePdfController;
use App\Http\Controllers\Tenant\MemberActivityExportController;
use App\Http\Controllers\Tenant\MemberImportSampleController;
use App\Http\Controllers\Tenant\MembershipApplicationImportSampleController;
use App\Http\Controllers\Tenant\MemberWebPushSubscriptionController;
use App\Http\Controllers\Tenant\StartDependentImpersonationController;
use App\Http\Controllers\Tenant\StatementPdfController;
use App\Http\Controllers\Tenant\StopImpersonationController;
use App\Http\Controllers\Tenant\StoredDatabaseBackupDownloadController;
use App\Http\Controllers\Tenant\TenantManifestController;
use App\Http\Controllers\Tenant\TermsConditionsDownloadController;
use App\Livewire\Tenant\ApplicationStatusPage;
use App\Livewire\Tenant\MembershipEnrollmentWizard;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::middleware([
    'web',
    PreventAccessFromCentralDomains::class,
    InitializeTenancyByDomain::class,
])->group(function () {
    Route::get('/', function () {
        return view('tenant.landing');
    })->name('tenant.home');

    Route::get('/locale/{locale}', LocaleSwitchController::class)
        ->name('tenant.locale.switch');

    Route::get('/membership', MembershipEnrollmentWizard::class)
        ->name('tenant.membership');

    Route::get('/apply', MembershipEnrollmentWizard::class)
        ->name('tenant.apply');

    Route::get('/application-status', ApplicationStatusPage::class)
        ->name('tenant.application.status');

    Route::redirect('/login', '/member/login')->name('tenant.login');

    Route::get('/downloads/terms-and-conditions', TermsConditionsDownloadController::class)
        ->name('tenant.downloads.terms-and-conditions');

    Route::get('/downloads/membership-application-import-sample', MembershipApplicationImportSampleController::class)
        ->name('tenant.downloads.membership-application-import-sample');

    Route::get('/downloads/loan-import-sample', LoanImportSampleController::class)
        ->name('tenant.downloads.loan-import-sample');

    Route::get('/downloads/loan-repayment-import-sample', LoanRepaymentImportSampleController::class)
        ->name('tenant.downloads.loan-repayment-import-sample');

    Route::get('/downloads/contribution-import-sample', ContributionImportSampleController::class)
        ->name('tenant.downloads.contribution-import-sample');

    Route::get('/downloads/member-import-sample', MemberImportSampleController::class)
        ->name('tenant.downloads.member-import-sample');

    Route::get('/downloads/legacy-members-import-sample', LegacyMemberImportSampleController::class)
        ->name('tenant.downloads.legacy-members-import-sample');

    Route::get('/downloads/legacy-loans-import-sample', LegacyLoanImportSampleController::class)
        ->name('tenant.downloads.legacy-loans-import-sample');

    Route::get('/downloads/legacy-payments-import-sample', LegacyPaymentImportSampleController::class)
        ->name('tenant.downloads.legacy-payments-import-sample');

    Route::get('/manifest.json', TenantManifestController::class)
        ->name('tenant.manifest');

    Route::get('/offline', fn () => view('offline'));

    Route::get('/storage/{path}', function (string $path) {
        return redirect(tenant_asset($path), 301);
    })->where('path', '.*')->name('tenant.storage-legacy');

    Route::middleware(['auth:tenant'])->group(function () {
        Route::redirect('/member/my-accounts', '/member/cash-account');
        Route::redirect('/member/support', '/member/help?tab=requests');
        Route::redirect('/member/contribution-settings', '/member/settings?tab=contributions');
        Route::redirect('/member/notification-preferences', '/member/settings?tab=notifications');
        Route::redirect('/member/my-profile', '/member/settings?tab=profile');
        Route::redirect('/member/my-messages', '/member/help?tab=messages');

        Route::get('/member/dependents/{dependent}/impersonate', StartDependentImpersonationController::class)
            ->name('tenant.member.dependents.impersonate');

        Route::post('/member/impersonation/stop', StopImpersonationController::class)
            ->name('tenant.member.impersonation.stop');

        Route::get('/member/statements/{statement}/pdf', [StatementPdfController::class, '__invoke'])
            ->name('tenant.member.statement.pdf');

        Route::get('/member/loans/{loan}/schedule/pdf', LoanSchedulePdfController::class)
            ->name('tenant.member.loan.schedule.pdf');

        Route::get('/member/activity/export', MemberActivityExportController::class)
            ->name('tenant.member.activity.export');

        Route::get('/admin/statements/{statement}/pdf', [StatementPdfController::class, 'admin'])
            ->name('tenant.admin.statement.pdf');

        Route::get('/admin/fiscal-closes/{fiscalClose}/exports/{fileKey}', FiscalCloseExportDownloadController::class)
            ->name('tenant.admin.fiscal-close.export');

        Route::get('/admin/system/backup-download', DatabaseBackupDownloadController::class)
            ->name('tenant.admin.system.backup-download');

        Route::get('/admin/system/backups/{databaseBackup}/download', StoredDatabaseBackupDownloadController::class)
            ->name('tenant.admin.system.backup-stored-download');

        Route::get('/admin/legacy-migration/classify-payments', LegacyPaymentClassifiedDownloadController::class)
            ->name('tenant.admin.legacy-migration.classify-payments');

        Route::get('/admin/legacy-migration/classified-payments/download', LegacyStoredClassifiedPaymentsDownloadController::class)
            ->name('tenant.admin.legacy-migration.classified-payments-download');

        Route::post('/admin/webpush/subscribe', [AdminWebPushSubscriptionController::class, 'store'])
            ->name('tenant.admin.webpush.subscribe.store');

        Route::delete('/admin/webpush/subscribe', [AdminWebPushSubscriptionController::class, 'destroy'])
            ->name('tenant.admin.webpush.subscribe.destroy');

        Route::post('/member/webpush/subscribe', [MemberWebPushSubscriptionController::class, 'store'])
            ->name('tenant.member.webpush.subscribe.store');

        Route::delete('/member/webpush/subscribe', [MemberWebPushSubscriptionController::class, 'destroy'])
            ->name('tenant.member.webpush.subscribe.destroy');

        Route::get('/direct-messages/{message}/attachment/{index}', DirectMessageAttachmentController::class)
            ->whereNumber('index')
            ->name('tenant.direct-messages.attachment');
    });
});
