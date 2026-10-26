<?php

namespace Tests\Feature\Gate12B;

use App\Jobs\ProcessChapterImport;
use App\Models\CanonicalChapter;
use App\Models\ChapterImport;
use App\Models\Edition;
use App\Models\EditionChapter;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueuedChapterImportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql'
            || getenv('DB_DATABASE') !== 'manga_gate07_test' || getenv('DB_HOST') !== '127.0.0.1') {
            $this->fail('Gate12B tests require isolated local PostgreSQL manga_gate07_test.');
        }
        parent::setUp();
        $this->assertSame('manga_gate07_test', DB::selectOne('SELECT current_database() AS name')->name);
        Storage::fake('manga_private');
        Queue::fake();
        config()->set('manga_content.async_imports', true);
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', null);
    }

    private function work(): array
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        config()->set('platform_admin.user_id', (string) $admin->id);
        config()->set('platform_admin.email', $admin->email);
        $series = Series::factory()->create(['slug' => 'queue-'.Str::lower(Str::random(8))]);
        $edition = Edition::create(['series_id' => $series->id, 'language_code' => 'ar', 'label' => 'Arabic',
            'status' => 'draft', 'reading_direction_hint' => 'vertical']);
        $canonical = CanonicalChapter::create(['series_id' => $series->id, 'display_number' => '1',
            'sort_key' => '1.00000000', 'kind' => 'regular', 'status' => 'draft']);
        $chapter = EditionChapter::create(['series_id' => $series->id, 'edition_id' => $edition->id,
            'canonical_chapter_id' => $canonical->id, 'status' => 'draft']);
        return compact('admin', 'series', 'chapter');
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAE0lEQVR4nGM8wcDAwMDAxIBMAQAP9ADOEjHWXgAAAABJRU5ErkJggg==');
    }

    public function test_queued_upload_is_private_and_worker_creates_one_draft_on_retry(): void
    {
        $work = $this->work();
        $path = '/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/upload';
        $key = (string) Str::uuid();
        $this->actingAs($work['admin'])->post($path, [
            'import_request_key' => $key,
            'upload' => UploadedFile::fake()->createWithContent('01.png', $this->png()),
        ])->assertRedirect()->assertSessionHasNoErrors();
        Queue::assertPushedOn('manga-imports', ProcessChapterImport::class);
        $import = ChapterImport::query()->where('request_key', $key)->firstOrFail();
        $this->assertSame('queued', $import->status);
        $this->assertSame(0, $work['chapter']->revisions()->count());
        $disk = Storage::disk('manga_private');
        $this->assertTrue($disk->exists($import->files[0]['key']));
        $this->post($path, [
            'import_request_key' => $key,
            'upload' => UploadedFile::fake()->createWithContent('01.png', $this->png()),
        ])->assertRedirect()->assertSessionHasNoErrors();
        Queue::assertPushed(ProcessChapterImport::class, 1);
        app()->call([new ProcessChapterImport($import->id), 'handle']);
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(1, $work['chapter']->revisions()->count());
        $this->assertSame(1, $work['chapter']->revisions()->firstOrFail()->pages()->count());
        $this->assertFalse($disk->exists($import->files[0]['key']));
        app()->call([new ProcessChapterImport($import->id), 'handle']);
        $this->assertSame(1, $work['chapter']->revisions()->count());
        $this->assertSame('draft', $work['series']->fresh()->publication_status);
    }

    public function test_malicious_payload_fails_processing_without_creating_a_revision(): void
    {
        $work = $this->work();
        $path = '/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/upload';
        $this->actingAs($work['admin'])->post($path, [
            'import_request_key' => (string) Str::uuid(),
            'upload' => UploadedFile::fake()->createWithContent('01.png', 'not-an-image'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $import = ChapterImport::query()->firstOrFail();
        app()->call([new ProcessChapterImport($import->id), 'handle']);
        $this->assertSame('failed', $import->fresh()->status);
        $this->assertSame(0, $work['chapter']->revisions()->count());
        $this->assertFalse(Storage::disk('manga_private')->exists($import->files[0]['key']));
    }

    public function test_reader_cannot_queue_or_read_admin_workbench(): void
    {
        $work = $this->work();
        $reader = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($reader)->post('/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/upload', [
            'import_request_key' => (string) Str::uuid(),
            'upload' => UploadedFile::fake()->createWithContent('01.png', $this->png()),
        ])->assertForbidden();
        $this->get('/admin/series/'.$work['series']->id.'/content')->assertForbidden();
        $this->assertSame(0, ChapterImport::query()->count());
    }

    public function test_misconfigured_queue_fails_closed_instead_of_synchronous_processing(): void
    {
        $work = $this->work();
        config()->set('queue.default', 'sync');
        $this->actingAs($work['admin'])->post('/admin/series/'.$work['series']->id.'/chapters/'.$work['chapter']->id.'/upload', [
            'import_request_key' => (string) Str::uuid(),
            'upload' => UploadedFile::fake()->createWithContent('01.png', $this->png()),
        ])->assertStatus(503);
        Queue::assertNothingPushed();
        $this->assertSame(0, ChapterImport::query()->count());
    }
}
