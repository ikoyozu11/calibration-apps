<?php

use App\Http\Controllers\CertificateServiceReportController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('/calibrations/{calibration}', 'pages::calibrations.show')->name('calibrations.show');
Route::get('/certificates/{certificate}/pdf', CertificateServiceReportController::class)->name('certificates.pdf');
Route::livewire('/certificates/{certificate}', 'pages::certificates.show')->name('certificates.show');
Route::livewire('/instruments', 'pages::instruments')->name('instruments.index');
Route::livewire('/instruments/{instrument}', 'pages::instruments.show')->name('instruments.show');
