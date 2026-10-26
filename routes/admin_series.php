<?php

use App\Http\Controllers\Admin\SeriesDraftController;
use Illuminate\Support\Facades\Route;

// Web session + verified email + single platform administrator only.
// Draft routes below remain unchanged; the protected P0 content routes are appended separately.
Route::middleware(['auth', 'verified', 'platform.admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/series', [SeriesDraftController::class, 'index'])->name('series.index');
        Route::get('/series/create', [SeriesDraftController::class, 'create'])->name('series.create');
        Route::post('/series', [SeriesDraftController::class, 'store'])->name('series.store');
        Route::get('/series/{series}/edit', [SeriesDraftController::class, 'edit'])->name('series.edit');
        Route::put('/series/{series}', [SeriesDraftController::class, 'update'])->name('series.update');
    });

// P0 content workbench: all endpoints share the existing single-admin middleware.
Route::middleware(['auth', 'verified', 'platform.admin'])->prefix('admin/series')
    ->name('admin.content.')->group(function (): void {
        $c = \App\Http\Controllers\Admin\SeriesContentController::class;
        Route::get('/{series}/content', [$c, 'show'])->name('show');
        Route::put('/{series}/content/details', [$c, 'details'])->name('details');
        Route::post('/{series}/cover', [$c, 'cover'])->name('cover');
        Route::post('/{series}/ownership/original', [$c, 'confirmOriginal'])->name('ownership.confirm');
        Route::post('/{series}/ownership/revoke', [$c, 'revokeOriginal'])->name('ownership.revoke');
        Route::get('/{series}/media/{asset}', [$c, 'media'])->name('media');
        Route::post('/{series}/editions', [$c, 'edition'])->name('edition');
        Route::post('/{series}/editions/{edition}/chapters', [$c, 'chapter'])->name('chapter');
        Route::post('/{series}/chapters/{chapter}/zip', [$c, 'zip'])->name('zip');
        Route::post('/{series}/chapters/{chapter}/upload', [$c, 'upload'])->name('upload');
        Route::post('/{series}/revisions/{revision}/reorder', [$c, 'reorder'])->name('reorder');
        Route::get('/{series}/chapters/{chapter}/readiness', [$c, 'ready'])->name('ready');
        Route::post('/{series}/chapters/{chapter}/publish', [$c, 'publish'])->name('publish');
        Route::post('/{series}/withdraw', [$c, 'withdraw'])->name('withdraw');
        Route::post('/{series}/rights/licenses', [$c, 'license'])->name('license');
        Route::get('/{series}/rights/licenses/{license}/proof', [$c, 'proof'])->name('proof');
        Route::post('/{series}/rights/licenses/{license}/approve', [$c, 'approve'])->name('approve');
        Route::post('/{series}/rights/grants', [$c, 'grant'])->name('grant');
    });

// Automated Arabic Manga & Manhwa Aggregator Workbench
Route::middleware(['auth', 'verified', 'platform.admin'])->prefix('admin/aggregator')
    ->name('admin.aggregator.')->group(function (): void {
        $a = \App\Http\Controllers\Admin\AggregatorController::class;
        Route::get('/', [$a, 'index'])->name('index');
        Route::post('/import', [$a, 'import'])->name('import');
        Route::post('/sync-popular', [$a, 'syncPopular'])->name('sync_popular');
    });

