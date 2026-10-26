<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EditionChapter;
use App\Models\RevisionPage;
use App\Models\Series;
use App\Services\Content\PrivateImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Applies the exact same public catalog and licensing scopes on every image request. */
class PublicReadingController extends PublicCatalogController
{
    private function readableChapter(Request $request, EditionChapter $chapter): EditionChapter
    {
        $language = $this->language($request);
        $territory = $this->audienceTerritory();
        $series = $this->eligibleSeries($territory, $language)
            ->whereKey($chapter->series_id)->firstOrFail();
        $eligible = EditionChapter::query()
            ->where('series_id', $series->id)
            ->whereKey($chapter->id)
            ->whereHas('edition', fn (Builder $edition) => $this->eligibleEdition($edition, $territory, $language));
        $this->eligibleChapter($eligible);
        return $eligible->with(['edition', 'currentPublishedRevision'])->firstOrFail();
    }

    public function cover(Request $request, string $slug, PrivateImages $images): \Symfony\Component\HttpFoundation\Response
    {
        $language = $this->language($request);
        $series = $this->eligibleSeries($this->audienceTerritory(), $language)
            ->where('slug', $slug)->with('coverAsset')->firstOrFail();
        abort_unless($series->coverAsset?->status === 'ready', 404);
        return $images->stream($series->coverAsset, true);
    }

    public function pages(Request $request, EditionChapter $chapter): JsonResponse
    {
        $chapter = $this->readableChapter($request, $chapter);
        $revision = $chapter->currentPublishedRevision;
        abort_unless($revision && $revision->status === 'published', 404);
        $pages = $revision->pages()->with('asset')->orderBy('page_index')->get();
        abort_if($pages->isEmpty(), 404);
        return response()->json(['data' => [
            'chapter_id' => $chapter->id,
            'edition_id' => $chapter->edition_id,
            'language' => $chapter->edition->language_code,
            'reading_direction' => $chapter->edition->reading_direction_hint,
            'pages' => $pages->map(fn ($page) => [
                'id' => $page->id, 'index' => $page->page_index,
                'width' => $page->width, 'height' => $page->height,
                'url' => route('api.v1.catalog.page.image', [
                    'chapter' => $chapter->id, 'page' => $page->id,
                    'lang' => $chapter->edition->language_code,
                ]),
            ])->all(),
        ]])->header('Cache-Control', 'public, max-age=3600, stale-while-revalidate=86400');
    }

    public function image(Request $request, EditionChapter $chapter, RevisionPage $page, PrivateImages $images): \Symfony\Component\HttpFoundation\Response
    {
        $chapter = $this->readableChapter($request, $chapter);
        abort_unless($chapter->current_published_revision_id === $page->chapter_revision_id, 404);
        $asset = $page->asset;
        abort_unless($asset && $asset->status === 'ready', 404);
        return $images->stream($asset, true);
    }
}
