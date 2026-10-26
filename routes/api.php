<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', static function () {
    try {
        DB::connection()->select('SELECT 1 AS ok');

        return response()->json([
            'status' => 'ok',
            'api_version' => 'v1',
            'database' => 'connected',
        ]);
    } catch (\Throwable $exception) {
        report($exception);

        return response()->json([
            'status' => 'error',
            'api_version' => 'v1',
            'database' => 'unavailable',
        ], 503);
    }
});
// Native accounts use scoped bearer tokens. Admin content editing stays on web sessions.
Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [\App\Http\Controllers\Api\V1\ReaderAuthController::class, 'register'])
        ->middleware('throttle:3,1')->name('api.v1.auth.register');
    Route::post('/auth/login', [\App\Http\Controllers\Api\V1\ReaderAuthController::class, 'login'])
        ->middleware('throttle:5,1')->name('api.v1.auth.login');

    Route::middleware(['auth:sanctum', 'mobile.account'])->group(function (): void {
        Route::get('/me', [\App\Http\Controllers\Api\V1\ReaderAuthController::class, 'me'])
            ->name('api.v1.me');
        Route::post('/auth/logout', [\App\Http\Controllers\Api\V1\ReaderAuthController::class, 'logout'])
            ->name('api.v1.auth.logout');
    });
    Route::get('/admin/dashboard/summary', \App\Http\Controllers\Admin\DashboardSummaryController::class)
        ->middleware(['auth:sanctum', 'mobile.account:admin'])
        ->name('api.v1.admin.dashboard.summary');
});
require __DIR__.'/catalog_api.php';
