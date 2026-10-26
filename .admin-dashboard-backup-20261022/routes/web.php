<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'platform.admin'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

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
