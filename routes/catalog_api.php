<?php

use App\Http\Controllers\Api\V1\PublicCatalogController;
use Illuminate\Support\Facades\Route;

// Gate 08A metadata routes retained; P0 adds separately guarded images/pages below.
// PublicCatalogController via publication + edition + license/grant scopes.
Route::prefix('v1/catalog')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/genres', [PublicCatalogController::class, 'genres'])->name('api.v1.catalog.genres');
    Route::get('/series', [PublicCatalogController::class, 'index'])->name('api.v1.catalog.index');
    Route::get('/series/{slug}', [PublicCatalogController::class, 'show'])->name('api.v1.catalog.show');
    Route::get('/series/{slug}/chapters', [PublicCatalogController::class, 'chapters'])->name('api.v1.catalog.chapters');
});

// P0: metadata and bytes are re-authorized on every request, including deep links.
Route::prefix('v1/catalog')->middleware('throttle:120,1')->group(function (): void {
    Route::get('/series/{slug}/cover', [\App\Http\Controllers\Api\V1\PublicReadingController::class, 'cover'])
        ->name('api.v1.catalog.cover');
    Route::get('/chapters/{chapter}/pages', [\App\Http\Controllers\Api\V1\PublicReadingController::class, 'pages'])
        ->name('api.v1.catalog.chapter.pages');
    Route::get('/chapters/{chapter}/pages/{page}/image', [\App\Http\Controllers\Api\V1\PublicReadingController::class, 'image'])
        ->name('api.v1.catalog.page.image');
});
