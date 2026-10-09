<?php

use App\Http\Controllers\CalibrationHistoryCsvExportController;
use App\Http\Controllers\CertificateServiceReportController;
use App\Http\Controllers\InstrumentTypeCsvExportController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::dashboard')->name('home');

Route::get('/calibrations/export.csv', CalibrationHistoryCsvExportController::class)->name('calibrations.export.csv');
Route::livewire('/calibrations/{calibration}', 'pages::calibrations.show')->name('calibrations.show');
Route::get('/certificates/{certificate}/pdf', CertificateServiceReportController::class)->name('certificates.pdf');
Route::livewire('/certificates/{certificate}', 'pages::certificates.show')->name('certificates.show');
Route::get('/instruments/export.csv', InstrumentTypeCsvExportController::class)->name('instruments.export.csv');
Route::livewire('/instruments', 'pages::instruments')->name('instruments.index');
Route::livewire('/instruments/{instrument}', 'pages::instruments.show')->name('instruments.show');
