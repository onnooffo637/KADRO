<?php

namespace Database\Seeders;

use App\Models\CanonicalChapter;
use App\Models\ChapterRevision;
use App\Models\Edition;
use App\Models\EditionChapter;
use App\Models\MediaAsset;
use App\Models\RevisionPage;
use App\Models\Series;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Gate06FictionalSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::connection()->getDatabaseName() !== 'manga_gate06_test' || !app()->environment('testing')) {
            throw new \RuntimeException('Gate 06 fixture seeder may run only in manga_gate06_test (APP_ENV=testing).');
        }

        DB::transaction(function (): void {
            $series = Series::firstOrCreate(
                ['slug' => 'gate06-fictional-starlight-atlas'],
                [
                    'primary_title' => 'أطلس ضوء النجوم — Starlight Atlas',
                    'format' => 'manhwa',
                    'original_language' => 'ko',
                    'publication_status' => 'draft',
                    'completion_status' => 'ongoing',
                    'synopsis' => 'Fictional metadata used only for isolated development testing.',
                ],
            );

            foreach ([
                ['1', '1.00000000', 'Opening', 1],
                ['12.5', '12.50000000', 'The Bridge', 5],
                ['extra', '13.00000000', 'A Special Detour', 17],
            ] as [$number, $rank, $title, $pageCount]) {
                $chapter = CanonicalChapter::firstOrCreate(
                    ['series_id' => $series->id, 'sort_key' => $rank],
                    ['display_number' => $number, 'title' => $title, 'kind' => $number === 'extra' ? 'extra' : 'regular'],
                );

                foreach (['ar' => 'Arabic fictional edition', 'en' => 'English fictional edition'] as $language => $label) {
                    $edition = Edition::firstOrCreate(
                        ['series_id' => $series->id, 'language_code' => $language, 'label' => $label],
                        ['status' => 'draft', 'reading_direction_hint' => $language === 'ar' ? 'rtl' : 'ltr'],
                    );
                    $editionChapter = EditionChapter::firstOrCreate(
                        ['edition_id' => $edition->id, 'canonical_chapter_id' => $chapter->id],
                        ['series_id' => $series->id, 'status' => 'draft'],
                    );
                    $revision = ChapterRevision::firstOrCreate(
                        ['edition_chapter_id' => $editionChapter->id, 'revision_no' => 1],
                        ['status' => 'draft'],
                    );
                    for ($i = 1; $i <= $pageCount; $i++) {
                        $path = sprintf('gate06/fictional/%s/%s/p%03d.png', $language, str_replace('.', '-', $number), $i);
                        $asset = MediaAsset::firstOrCreate(
                            ['storage_key' => $path],
                            [
                                'storage_disk' => 'private',
                                'mime' => 'image/png',
                                'sha256' => str_repeat('0', 64),
                                'bytes' => 1,
                                'width' => 800,
                                'height' => 1200,
                                'status' => 'quarantined',
                            ],
                        );
                        RevisionPage::firstOrCreate(
                            ['chapter_revision_id' => $revision->id, 'page_index' => $i],
                            ['media_asset_id' => $asset->id, 'width' => 800, 'height' => 1200],
                        );
                    }
                }
            }
        });
    }
}
