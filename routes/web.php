<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Inertia\Inertia;

Route::get('/', function (Request $request) {
    if ($request->user() instanceof \App\Models\User && $request->user()->isPlatformAdmin()) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth', 'verified', 'platform.admin'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::get('admin/dashboard/summary', \App\Http\Controllers\Admin\DashboardSummaryController::class)
        ->name('admin.dashboard.summary');
});

// A real, dashboard-scoped native token may open the existing admin panel in
// an isolated WebView session. All following content routes keep their web guards.
Route::get('admin/mobile-session', \App\Http\Controllers\Admin\MobileAdminSessionController::class)
    ->middleware(['auth:sanctum', 'mobile.account:admin'])
    ->name('admin.mobile.session');

require __DIR__.'/settings.php';

# GATE05_LOCAL_GALLERY_START
if (app()->environment('local')) {
    \Illuminate\Support\Facades\Route::get('/_dev/design-gallery', static fn () => \Inertia\Inertia::render('gate05-gallery'));
}
# GATE05_LOCAL_GALLERY_END

# GATE05_B_LOCAL_GALLERY_START
if (app()->environment('local')) {
    \Illuminate\Support\Facades\Route::get('/_dev/design-gallery-b', static fn () => \Inertia\Inertia::render('gate05-gallery-b'));
}
# GATE05_B_LOCAL_GALLERY_END

require __DIR__.'/admin_series.php';
