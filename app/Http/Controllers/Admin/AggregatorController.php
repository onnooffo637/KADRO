<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Aggregator\MangaAggregatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AggregatorController extends Controller
{
    /**
     * Browse trending Arabic manga/manhwa and search across sources.
     */
    public function index(Request $request, MangaAggregatorService $aggregator): JsonResponse|Response
    {
        $query = $request->query('q');
        $format = $request->query('format'); // manga, manhwa, all

        if (! empty($query)) {
            $items = $aggregator->searchArabicManga((string) $query, 15);
        } else {
            $items = $aggregator->getPopularArabicManga(20, $format === 'all' ? null : $format);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'data' => $items,
            ]);
        }

        return Inertia::render('Admin/Aggregator/Index', [
            'initialItems' => $items,
            'filters' => [
                'q' => $query,
                'format' => $format ?? 'all',
            ],
        ]);
    }

    /**
     * Import a specific manga/manhwa with its Arabic chapters.
     */
    public function import(Request $request, MangaAggregatorService $aggregator): JsonResponse
    {
        $validated = $request->validate([
            'source_id' => 'required|string',
            'chapters' => 'nullable|integer|min:1|max:20',
        ]);

        $adminId = (int) $request->user()->id;
        $maxChapters = (int) ($validated['chapters'] ?? 5);

        $res = $aggregator->importSeries($validated['source_id'], $maxChapters, $adminId);

        return response()->json($res, $res['success'] ? 200 : 422);
    }

    /**
     * Bulk sync top popular Arabic manga and manhwa.
     */
    public function syncPopular(Request $request, MangaAggregatorService $aggregator): JsonResponse
    {
        $validated = $request->validate([
            'count' => 'nullable|integer|min:1|max:30',
            'chapters' => 'nullable|integer|min:1|max:10',
        ]);

        $adminId = (int) $request->user()->id;
        $count = (int) ($validated['count'] ?? 10);
        $chapters = (int) ($validated['chapters'] ?? 3);

        $result = $aggregator->syncPopular($count, $chapters, $adminId);

        return response()->json([
            'status' => 'success',
            'data' => $result,
            'message' => "تم استيراد {$result['total_synced']} عمل بنجاح.",
        ]);
    }
}
