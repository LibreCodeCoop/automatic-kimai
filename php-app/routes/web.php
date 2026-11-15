<?php

use App\Http\Controllers\AutomaticKimaiController;
use Illuminate\Support\Facades\Route;

Route::get('/',[AutomaticKimaiController::class,'home'])->name('kimai.home');
Route::post('/setup',[AutomaticKimaiController::class,'setup'])->name('kimai.setup');
Route::get('/catalog',[AutomaticKimaiController::class,'catalog'])->name('kimai.catalog');
Route::post('/catalog/preview',[AutomaticKimaiController::class,'catalogPreview'])->name('kimai.catalog-preview');
Route::post('/connectors',[AutomaticKimaiController::class,'connectors'])->name('kimai.connectors');
Route::post('/attendance/preview',[AutomaticKimaiController::class,'attendancePreview'])->name('kimai.attendance-preview');
Route::post('/sources',[AutomaticKimaiController::class,'sources'])->name('kimai.sources');
Route::post('/analyze',[AutomaticKimaiController::class,'capture'])->name('kimai.analyze');
Route::post('/manual',[AutomaticKimaiController::class,'manual'])->name('kimai.manual');
