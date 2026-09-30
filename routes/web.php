<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('/dashboard', DashboardController::class);
Route::middleware(['auth', 'throttle:30,1'])->group(function () {
    Route::get('/exports/{source}/{format}', ExportController::class)->whereIn('source', ['personnel', 'positions'])->whereIn('format', ['xlsx', 'csv'])->name('exports');
    Route::get('/templates/{source}', TemplateController::class)->whereIn('source', ['personnel', 'positions'])->name('templates');
});
