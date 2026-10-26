<?php

namespace Tests\Feature\Gate10;

use App\Models\CanonicalChapter;
use App\Models\Edition;
use App\Models\EditionChapter;
use App\Models\RightsGrant;
use App\Models\RightsLicense;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/** Fictional 1x1 images only; hard guard against DB mix-ups. */
class PublishingJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql'
            || getenv('DB_DATABASE') !== 'manga_gate07_test' || getenv('DB_HOST') !== '127.0.0.1') {
            $this->fail('Gate10 tests ONLY on isolated local PostgreSQL manga_gate07_test.');
        }
        parent::setUp();
        $this->assertSame('manga_gate07_test', DB::selectOne('SELECT current_database() AS name')->name);
        Storage::fake('manga_private');
        config()->set('manga_catalog.public_territory', 'EG');
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        config()->set('platform_admin.user_id', (string) $admin->id);
        config()->set('platform_admin.email', $admin->email);
        return $admin;
    }

    private function data(): array
    {
        $series = Series::factory()->create(['slug' => 'original-test-'.Str::lower(Str::random(10))]);
        $edition = Edition::create([
            'series_id' => $series->id, 'language_code' => 'ar', 'label' => 'Arabic',
            'status' => 'draft', 'reading_direction_hint' => 'vertical',
        ]);
        $canonical = CanonicalChapter::create([
            'series_id' => $series->id, 'display_number' => '1', 'sort_key' => '1.00000000',
            'status' => 'draft', 'kind' => 'regular',
        ]);
        $chapter = EditionChapter::create([
            'series_id' => $series->id, 'edition_id' => $edition->id,
            'canonical_chapter_id' => $canonical->id, 'status' => 'draft',
        ]);
        return compact('series', 'edition', 'chapter');
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAE0lEQVR4nGM8wcDAwMDAxIBMAQAP9ADOEjHWXgAAAABJRU5ErkJggg==');
    }

    private function coloredPng(int $red): string
    {
        $image = imagecreatetruecolor(2, 3);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, 0, 0));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    private function archive(array $entries, string $filename = 'chapter.zip'): UploadedFile
    {
        if (! class_exists(ZipArchive::class)) $this->fail('PHP zip extension required for ZIP upload tests.');
        $path = tempnam(sys_get_temp_dir(), 'g10zip');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $contents) $zip->addFromString($name, $contents);
        $zip->close();
        return new UploadedFile($path, $filename, 'application/zip', null, true);
    }

    private function uploadDraft(User $admin, array $work, ?array $sources = null): void
    {
        $this->actingAs($admin)->post('/admin/series/'.$work['series']->id.'/cover', [
            'upload' => UploadedFile::fake()->createWithContent('cover.png', $this->png()),
        ])->assertRedirect();
        $this->post('/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/zip', [
            'upload' => $this->archive($sources ?? ['10.png' => $this->png(), '2.png' => $this->png(), '1.png' => $this->png()]),
        ])->assertRedirect();
    }

    public function test_import_request_key_prevents_duplicate_revision_on_retry(): void
    {
        $admin = $this->admin();
        $work = $this->data();
        $url = '/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/upload';
        $key = (string) Str::uuid();
        $this->actingAs($admin)->post($url, [
            'import_request_key' => $key,
            'upload' => UploadedFile::fake()->createWithContent('page.png', $this->png()),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $work['chapter']->revisions()->count());
        $this->post($url, [
            'import_request_key' => $key,
            'upload' => UploadedFile::fake()->createWithContent('page.png', $this->png()),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $work['chapter']->revisions()->count());
        $this->assertSame(1, $work['chapter']->revisions()->firstOrFail()->pages()->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'chapter.revision_uploaded')->count());

        // Intentionally uploading again with a new key creates a fresh draft revision.
        $this->post($url, [
            'import_request_key' => (string) Str::uuid(),
            'upload' => UploadedFile::fake()->createWithContent('page.png', $this->png()),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $work['chapter']->revisions()->count());
    }

    public function test_invalid_import_request_key_does_not_create_revision(): void
    {
        $admin = $this->admin();
        $work = $this->data();
        $this->actingAs($admin)->post('/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/upload', [
            'import_request_key' => 'not-a-uuid',
            'upload' => UploadedFile::fake()->createWithContent('page.png', $this->png()),
        ])->assertSessionHasErrors('import_request_key');
        $this->assertSame(0, $work['chapter']->revisions()->count());
    }

    public function test_unverified_reader_and_guest_cannot_upload_publish_or_see_private_proof(): void
    {
        $admin = $this->admin();
        $work = $this->data();
        $url = '/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/zip';
        $this->post($url, [])->assertRedirect('/login');
        $reader = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($reader)->post($url, [])->assertForbidden();
        $this->actingAs($reader)->post('/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/publish', ['revision_id' => (string) Str::ulid()])->assertForbidden();
        $this->actingAs($reader)->get('/admin/series/'.$work['series']->id.'/content')->assertForbidden();
    }

    public function test_invalid_cover_and_zip_traversal_fail_closed(): void
    {
        $admin = $this->admin();
        $work = $this->data();
        $this->actingAs($admin)->post('/admin/series/'.$work['series']->id.'/cover', [
            'upload' => UploadedFile::fake()->createWithContent('cover.png', '<script>not an image</script>'),
        ])->assertSessionHasErrors('upload');
        $this->post('/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/zip', [
            'upload' => $this->archive(['../escape.png' => $this->png()]),
        ])->assertSessionHasErrors('upload');
        $this->assertDatabaseMissing('chapter_revisions', ['edition_chapter_id' => $work['chapter']->id]);
    }

    public function test_zip_order_preview_and_publish_require_approved_rights(): void
    {
        $admin = $this->admin();
        $work = $this->data();
        $this->assertSame(['1.png', '2.png', '10.png'], array_column(app(\App\Services\Content\SafeChapterZip::class)->read(
            $this->archive(['10.png' => $this->png(), '2.png' => $this->png(), '1.png' => $this->png()])
        ), 'name'));
        // Give every page different bytes so hash order really tests natural ZIP sorting.
        $sources = [
            '10.png' => $this->coloredPng(10),
            '2.png' => $this->coloredPng(2),
            '1.png' => $this->coloredPng(1),
        ];
        $this->uploadDraft($admin, $work, $sources);
        $rev = $work['chapter']->revisions()->firstOrFail();
        $this->assertSame([1, 2, 3], $rev->pages()->orderBy('page_index')->pluck('page_index')->all());
        $checksums = $rev->pages()->with('asset')->orderBy('page_index')->get()
            ->map(fn ($page) => $page->asset->sha256)->all();
        $this->assertSame([hash('sha256', $sources['1.png']), hash('sha256', $sources['2.png']), hash('sha256', $sources['10.png'])], $checksums);
        $this->assertSame('draft', $work['series']->fresh()->publication_status);
        $this->post('/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/publish', [
            'revision_id' => $rev->id,
        ])->assertSessionHasErrors('publish');
        $this->getJson('/api/v1/catalog/chapters/'.$work['chapter']->id.'/pages?lang=ar')->assertNotFound();
    }

    public function test_rights_approved_publish_mobile_images_and_withdraw_revoke_access(): void
    {
        $admin = $this->admin();
        $work = $this->data();
        $this->uploadDraft($admin, $work);
        $now = now();
        $license = RightsLicense::create([
            'holder_name' => 'Fictional original artwork by test author', 'document_ref' => 'fixture-original-license',
            'approval_status' => 'approved', 'approved_by' => $admin->id, 'approved_at' => $now,
            'valid_from' => $now->copy()->subDay(), 'valid_until' => $now->copy()->addMonth(),
        ]);
        $grant = RightsGrant::create([
            'license_id' => $license->id, 'series_id' => $work['series']->id,
            'edition_id' => $work['edition']->id, 'status' => 'active',
            'can_publish' => true, 'can_translate' => true,
            'valid_from' => $now->copy()->subDay(), 'valid_until' => $now->copy()->addMonth(),
        ]);
        DB::table('rights_territories')->insert(['grant_id' => $grant->id, 'territory_code' => 'EG']);
        DB::table('rights_languages')->insert(['grant_id' => $grant->id, 'language_code' => 'ar']);
        $rev = $work['chapter']->revisions()->firstOrFail();
        $post = '/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/publish';
        $this->post($post, ['revision_id' => $rev->id])->assertRedirect();
        $this->post($post, ['revision_id' => $rev->id])->assertRedirect(); // idempotency
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'chapter.published')->where('subject_id', $work['chapter']->id)->count());
        $this->getJson('/api/v1/catalog/series?lang=ar')->assertOk()->assertJsonFragment(['slug' => $work['series']->slug]);
        $pages = $this->getJson('/api/v1/catalog/chapters/'.$work['chapter']->id.'/pages?lang=ar')->assertOk()->json('data.pages');
        $this->assertCount(3, $pages);
        $this->assertSame([1, 2, 3], array_column($pages, 'index'));
        $this->assertStringNotContainsString('storage_key', json_encode($pages));
        $this->get($pages[0]['url'])->assertOk()->assertHeader('Content-Type', 'image/png');
        config()->set('manga_catalog.public_territory', null);
        $this->get($pages[0]['url'])->assertNotFound();
        config()->set('manga_catalog.public_territory', 'EG');
        $grant->update(['status' => 'revoked']);
        $this->get($pages[0]['url'])->assertNotFound();
        $grant->update(['status' => 'active']);
        $this->post('/admin/series/'.$work['series']->id.'/withdraw', ['confirm' => 'WITHDRAW'])->assertRedirect();
        $this->get($pages[0]['url'])->assertNotFound();
        $this->getJson('/api/v1/catalog/chapters/'.$work['chapter']->id.'/pages?lang=ar')->assertNotFound();
    }
    public function test_cbz_chapter_uses_archive_preflight_and_a_private_draft_revision(): void
    {
        $admin = $this->admin();
        $work = $this->data();
        $file = $this->archive(['10.png' => $this->png(), '2.png' => $this->png(), '1.png' => $this->png()], 'chapter.cbz');
        $this->actingAs($admin)->post('/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/upload', ['upload' => $file])->assertRedirect();
        $revision = $work['chapter']->revisions()->firstOrFail();
        $this->assertSame('draft', $revision->status);
        $this->assertSame([1, 2, 3], $revision->pages()->orderBy('page_index')->pluck('page_index')->all());
        $this->getJson('/api/v1/catalog/chapters/'.$work['chapter']->id.'/pages?lang=ar')->assertNotFound();
    }

    public function test_multiple_images_upload_natural_sort_and_no_automatic_publication(): void
    {
        $admin = $this->admin();
        $work = $this->data();
        $sources = [
            '10.png' => $this->coloredPng(10),
            '1.png' => $this->coloredPng(1),
            '2.png' => $this->coloredPng(2),
        ];
        $images = [];
        foreach ($sources as $name => $bytes) {
            $images[] = UploadedFile::fake()->createWithContent($name, $bytes);
        }
        $this->actingAs($admin)->post('/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/upload', ['images' => $images])->assertRedirect();
        $rev = $work['chapter']->revisions()->firstOrFail();
        $this->assertSame('draft', $rev->status);
        $this->assertSame([1, 2, 3], $rev->pages()->orderBy('page_index')->pluck('page_index')->all());
        $checksums = $rev->pages()->with('asset')->orderBy('page_index')->get()
            ->map(fn ($page) => $page->asset->sha256)->all();
        $this->assertSame([hash('sha256', $sources['1.png']), hash('sha256', $sources['2.png']), hash('sha256', $sources['10.png'])], $checksums);
        $this->assertSame('draft', $work['series']->fresh()->publication_status);
    }

    public function test_single_image_and_invalid_pdf_cannot_publish_or_modify_existing_revision(): void
    {
        $admin = $this->admin();
        $work = $this->data();
        $url = '/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/upload';
        $this->actingAs($admin)->post($url, [
            'upload' => UploadedFile::fake()->createWithContent('page.png', $this->png()),
        ])->assertRedirect();
        $this->assertSame(1, $work['chapter']->revisions()->count());
        $this->post($url, [
            'upload' => UploadedFile::fake()->createWithContent('fake.pdf', '<script>unsafe</script>'),
        ])->assertSessionHasErrors('upload');
        $this->assertSame(1, $work['chapter']->revisions()->count());
    }

    public function test_mixed_upload_modes_and_non_admin_are_refused(): void
    {
        $admin = $this->admin();
        $work = $this->data();
        $url = '/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/upload';
        $reader = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($reader)->post($url, [
            'upload' => $this->archive(['1.png' => $this->png()]),
        ])->assertForbidden();
        $this->actingAs($admin)->post($url, [
            'upload' => $this->archive(['1.png' => $this->png()]),
            'images' => [UploadedFile::fake()->createWithContent('1.png', $this->png())],
        ])->assertSessionHasErrors('upload');
        $this->assertSame(0, $work['chapter']->revisions()->count());
    }

    public function test_real_two_page_pdf_import_if_poppler_is_available(): void
    {
        // Optional integration test; users can still upload ZIP/CBZ/images without Poppler.
        try {
            foreach (['pdfinfo_binary' => 'pdfinfo', 'pdftoppm_binary' => 'pdftoppm'] as $key => $default) {
                $binary = (string) config('manga_content.'.$key, $default);
                $check = new \Symfony\Component\Process\Process([$binary, '-v']);
                $check->run();
                if (! $check->isSuccessful()) $this->markTestSkipped('Poppler is not installed/configured.');
            }
        } catch (\Throwable) {
            $this->markTestSkipped('Poppler is not installed/configured.');
        }
        $fixture = base_path('tests/Fixtures/fictional_two_pages.pdf');
        $this->assertFileExists($fixture);
        $admin = $this->admin();
        $work = $this->data();
        $this->actingAs($admin)->post('/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/upload', [
            'upload' => new UploadedFile($fixture, 'two_pages.pdf', 'application/pdf', null, true),
        ])->assertRedirect();
        $rev = $work['chapter']->revisions()->firstOrFail();
        $this->assertSame([1, 2], $rev->pages()->orderBy('page_index')->pluck('page_index')->all());
        $this->assertSame('draft', $rev->status);
    }

}
