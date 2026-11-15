<?php

use App\Http\Controllers\AutomaticKimaiController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\NextcloudLoginController;
use App\Http\Controllers\GitHubAccountController;
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

// Explicit localhost-only Telegram MTProto onboarding; QR and 2FA must not
// appear in URLs, logs or any public network listener.
Route::post('/telegram/configure',[TelegramController::class,'configure'])->name('kimai.telegram.configure');
Route::get('/telegram/status',[TelegramController::class,'status'])->name('kimai.telegram.status');
Route::post('/telegram/qr',[TelegramController::class,'qr'])->middleware('throttle:4,1')->name('kimai.telegram.qr');
Route::post('/telegram/2fa',[TelegramController::class,'twoFactor'])->middleware('throttle:4,1')->name('kimai.telegram.2fa');
Route::post('/telegram/activate',[TelegramController::class,'activate'])->name('kimai.telegram.activate');
Route::get('/telegram/groups',[TelegramController::class,'groups'])->middleware('throttle:10,1')->name('kimai.telegram.groups');
Route::post('/telegram/search',[TelegramController::class,'search'])->middleware('throttle:30,1')->name('kimai.telegram.search');
Route::get('/telegram/communities',[TelegramController::class,'communities'])->middleware('throttle:10,1')->name('kimai.telegram.communities');
Route::get('/telegram/avatar',[TelegramController::class,'avatar'])->middleware('throttle:20,1')->name('kimai.telegram.avatar');
Route::post('/telegram/disconnect',[TelegramController::class,'disconnect'])->middleware('throttle:5,1')->name('kimai.telegram.disconnect');
Route::post('/telegram/groups',[TelegramController::class,'selectGroups'])->middleware('throttle:10,1')->name('kimai.telegram.groups.save');
Route::post('/telegram/disable',[TelegramController::class,'disable'])->name('kimai.telegram.disable');

// Local-only read-only GitHub account display and explicit token removal.
Route::get('/github/profile',[GitHubAccountController::class,'profile'])->middleware('throttle:15,1')->name('kimai.github.profile');
Route::get('/github/avatar',[GitHubAccountController::class,'avatar'])->middleware('throttle:15,1')->name('kimai.github.avatar');
Route::post('/github/disconnect',[GitHubAccountController::class,'disconnect'])->middleware('throttle:5,1')->name('kimai.github.disconnect');

// Local-only Nextcloud Login Flow v2. Each client gets a revocable app password.
Route::get('/nextcloud/status',[NextcloudLoginController::class,'status'])->name('kimai.nextcloud.status');
Route::get('/nextcloud/profile',[NextcloudLoginController::class,'profile'])->middleware('throttle:20,1')->name('kimai.nextcloud.profile');
Route::get('/nextcloud/avatar',[NextcloudLoginController::class,'avatar'])->middleware('throttle:30,1')->name('kimai.nextcloud.avatar');
Route::post('/nextcloud/source',[NextcloudLoginController::class,'source'])->middleware('throttle:30,1')->name('kimai.nextcloud.source');
Route::post('/nextcloud/disconnect',[NextcloudLoginController::class,'disconnect'])->middleware('throttle:5,1')->name('kimai.nextcloud.disconnect');
Route::post('/nextcloud/start',[NextcloudLoginController::class,'start'])->middleware('throttle:5,1')->name('kimai.nextcloud.start');
Route::post('/nextcloud/poll',[NextcloudLoginController::class,'poll'])->middleware('throttle:25,1')->name('kimai.nextcloud.poll');
