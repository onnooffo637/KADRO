<?php

namespace App\Console\Commands;

use App\Services\Aggregator\MangaAggregatorService;
use Illuminate\Console\Command;

class KadroSyncPopularCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'kadro:sync-popular 
                            {--count=10 : عدد الأعمال المراد جلبها}
                            {--chapters=3 : عدد الفصول لكل عمل}
                            {--type=all : نوع العمل (manga, manhwa, all)}';

    /**
     * The console command description.
     */
    protected $description = 'جلب وتحديث أشهر المانجا والمانهوا المترجمة للعربية فورياً من المصادر الكبرى';

    /**
     * Execute the console command.
     */
    public function handle(MangaAggregatorService $aggregator): int
    {
        $count = (int) $this->option('count');
        $chapters = (int) $this->option('chapters');
        $type = $this->option('type') === 'all' ? null : $this->option('type');

        $this->info("🚀 جاري الاتصال بالمصادر لجلب أشهر {$count} مانجا/مانهوا مترجمة للعربية...");

        $popular = $aggregator->getPopularArabicManga($count, $type);
        if (empty($popular)) {
            $this->error('لم يتم العثور على أعمال متطابقة أو تعذر الاتصال بالمصدر.');
            return self::FAILURE;
        }

        $this->table(
            ['ID', 'العنوان', 'النوع', 'اللغة الأصلية', 'الحالة'],
            array_map(fn ($m) => [
                $m['source_id'],
                $m['title'],
                $m['format'],
                $m['original_language'],
                $m['status'],
            ], $popular)
        );

        $bar = $this->output->createProgressBar(count($popular));
        $bar->start();

        $successCount = 0;
        foreach ($popular as $item) {
            $this->line("\n📥 استيراد: {$item['title']}...");
            $res = $aggregator->importSeries($item['source_id'], $chapters);

            if ($res['success']) {
                $this->info("   ✅ تم الاستيراد بنجاح ({$res['chapters_count']} فصول)");
                $successCount++;
            } else {
                $this->warn("   ⚠️ " . $res['message']);
            }

            $bar->advance();
            usleep(200000); // 200ms
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("🎉 اكتملت العملية! تم استيراد ونشر {$successCount} مانجا ومانهوا على المنصة جاهزة للقراءة.");

        return self::SUCCESS;
    }
}
