<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\CalibrationHistoryCsvExportController;
use App\Http\Controllers\CalibrationLabelController;
use App\Http\Controllers\CalibrationReportExportController;
use App\Http\Controllers\CertificateServiceReportController;
use App\Http\Controllers\ExternalCalibrationDocumentDownloadController;
use App\Http\Controllers\InstrumentTypeCsvExportController;
use App\StagingPreflight;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', LogoutController::class)
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'application.role'])->group(function (): void {
    Route::livewire('/', 'pages::dashboard')->name('home');
    Route::livewire('/profile', 'pages::profile')->name('profile.show');
    Route::livewire('/users', 'pages::users')->name('users.index');

    Route::get('/calibrations/export.csv', CalibrationHistoryCsvExportController::class)->name('calibrations.export.csv');
    Route::get('/calibration-reports/export.xlsx', CalibrationReportExportController::class)->name('calibration-reports.export');
    Route::livewire('/calibrations', 'pages::calibrations')->name('calibrations.index');
    Route::redirect('/calibration-reports', '/calibrations?tab=reports')->name('calibration-reports.index');
    Route::livewire('/calibrations/report/{instrument}/{instrumentType}/{year}', 'pages::calibrations.show')
        ->whereNumber('instrument')
        ->whereNumber('instrumentType')
        ->whereNumber('year')
        ->name('calibrations.report-detail');
    Route::get('/calibrations/{calibration}/label/preview', [CalibrationLabelController::class, 'preview'])
        ->whereNumber('calibration')
        ->name('calibrations.label.preview');
    Route::get('/calibrations/{calibration}/label/download', [CalibrationLabelController::class, 'download'])
        ->whereNumber('calibration')
        ->name('calibrations.label.download');
    Route::get('/calibrations/{calibration}/label/print', [CalibrationLabelController::class, 'print'])
        ->whereNumber('calibration')
        ->name('calibrations.label.print');
    Route::get('/calibrations/{calibration}/external-documents/{document}/download', ExternalCalibrationDocumentDownloadController::class)
        ->whereNumber('calibration')
        ->whereNumber('document')
        ->name('calibrations.external-documents.download');
    Route::get('/calibrations/{calibration}/external-documents/{document}/view', [ExternalCalibrationDocumentDownloadController::class, 'view'])
        ->whereNumber('calibration')
        ->whereNumber('document')
        ->name('calibrations.external-documents.view');
    Route::livewire('/calibrations/{calibration}', 'pages::calibrations.show')
        ->whereNumber('calibration')
        ->name('calibrations.show');
    Route::get('/certificates/{certificate}/pdf', CertificateServiceReportController::class)
        ->whereNumber('certificate')
        ->name('certificates.pdf');
    Route::get('/certificates/{certificate}/download', [CertificateServiceReportController::class, 'download'])
        ->whereNumber('certificate')
        ->name('certificates.download');
    Route::post('/calibrations/{calibration}/certificate/refresh', [CertificateServiceReportController::class, 'refresh'])
        ->whereNumber('calibration')
        ->name('calibrations.certificate.refresh');
    Route::livewire('/certificates/{certificate}', 'pages::certificates.show')
        ->whereNumber('certificate')
        ->name('certificates.show');
    Route::get('/instruments/export.csv', InstrumentTypeCsvExportController::class)->name('instruments.export.csv');
    Route::livewire('/instruments', 'pages::instruments')->name('instruments.index');
    Route::livewire('/standards', 'pages::standards')->name('standards.index');
    Route::livewire('/activities', 'pages::activities')->name('activities.index');
    Route::livewire('/instruments/{instrument}/types/{instrumentType}/calibrations/start', 'pages::instruments.start-calibration')
        ->whereNumber('instrument')
        ->whereNumber('instrumentType')
        ->name('instruments.types.calibrations.start');
    Route::livewire('/instruments/{instrument}/types/{instrumentType}/test-components', 'pages::instruments.test-components')
        ->whereNumber('instrument')
        ->whereNumber('instrumentType')
        ->name('instruments.types.test-components');
    Route::livewire('/instruments/{instrument}/types/{instrumentType}/standards', 'pages::instruments.standards')
        ->whereNumber('instrument')
        ->whereNumber('instrumentType')
        ->name('instruments.types.standards');
    Route::livewire('/instruments/{instrument}/types/{instrumentType}', 'pages::instruments.show')
        ->whereNumber('instrument')
        ->whereNumber('instrumentType')
        ->name('instruments.types.show');
    Route::livewire('/instruments/{instrument}', 'pages::instruments.show')
        ->whereNumber('instrument')
        ->name('instruments.show');

    if (config('database.staging_preflight_http')) {
        Route::get('/__staging-preflight', function (StagingPreflight $preflight) {
            $report = $preflight->inspect();

            return response()->json($report, $report['ok'] ? 200 : 409);
        })->name('staging.preflight');
    }
});
