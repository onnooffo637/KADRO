<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EditionChapter;
use App\Models\Series;
use Illuminate\Http\JsonResponse;

/** Read-only dashboard data. Access is restricted by the web admin middleware. */
class DashboardSummaryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $recent = Series::query()
            ->with('coverAsset:id,status')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'slug', 'primary_title', 'format', 'publication_status', 'updated_at', 'cover_media_asset_id'])
            ->map(fn (Series $series) => [
                'id' => $series->id,
                'title' => $series->primary_title,
                'format' => $series->format,
                'status' => $series->publication_status,
                'updated_at' => $series->updated_at?->toIso8601String(),
                'cover_url' => $series->coverAsset?->status === 'ready'
                    ? route('admin.content.media', [$series, $series->coverAsset]) : null,
            ]);

        return response()->json([
            'stats' => [
                'series' => Series::query()->count(),
                'drafts' => Series::query()->where('publication_status', 'draft')->count(),
                'published' => Series::query()->where('publication_status', 'published')->count(),
                'published_chapters' => EditionChapter::query()
                    ->where('status', 'published')
                    ->whereNotNull('current_published_revision_id')
                    ->count(),
            ],
            'recent' => $recent,
        ]);
    }
}
