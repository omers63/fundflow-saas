<?php

declare(strict_types=1);

use App\Http\Controllers\LocaleSwitchController;
use App\Http\Controllers\Tenant\ContributionImportSampleController;
use App\Http\Controllers\Tenant\DatabaseBackupDownloadController;
use App\Http\Controllers\Tenant\DirectMessageAttachmentController;
use App\Http\Controllers\Tenant\FiscalCloseExportDownloadController;
use App\Http\Controllers\Tenant\LoanImportSampleController;
use App\Http\Controllers\Tenant\LoanRepaymentImportSampleController;
use App\Http\Controllers\Tenant\MemberImportSampleController;
use App\Http\Controllers\Tenant\MembershipApplicationImportSampleController;
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
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
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

    Route::get('/manifest.json', TenantManifestController::class)
        ->name('tenant.manifest');

    Route::get('/offline', fn () => view('offline'));

    Route::get('/storage/{path}', function (string $path) {
        return redirect(tenant_asset($path), 301);
    })->where('path', '.*')->name('tenant.storage-legacy');

    Route::middleware(['auth:tenant'])->group(function () {
        Route::get('/member/dependents/{dependent}/impersonate', StartDependentImpersonationController::class)
            ->name('tenant.member.dependents.impersonate');

        Route::post('/member/impersonation/stop', StopImpersonationController::class)
            ->name('tenant.member.impersonation.stop');

        Route::get('/member/statements/{statement}/pdf', [StatementPdfController::class, '__invoke'])
            ->name('tenant.member.statement.pdf');

        Route::get('/admin/statements/{statement}/pdf', [StatementPdfController::class, 'admin'])
            ->name('tenant.admin.statement.pdf');

        Route::get('/admin/fiscal-closes/{fiscalClose}/exports/{fileKey}', FiscalCloseExportDownloadController::class)
            ->name('tenant.admin.fiscal-close.export');

        Route::get('/admin/system/backup-download', DatabaseBackupDownloadController::class)
            ->name('tenant.admin.system.backup-download');

        Route::get('/admin/system/backups/{databaseBackup}/download', StoredDatabaseBackupDownloadController::class)
            ->name('tenant.admin.system.backup-stored-download');

        Route::get('/direct-messages/{message}/attachment/{index}', DirectMessageAttachmentController::class)
            ->whereNumber('index')
            ->name('tenant.direct-messages.attachment');
    });
});
