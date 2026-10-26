<?php

namespace App\Console\Commands;

use App\Services\Aggregator\MangaAggregatorService;
use Illuminate\Console\Command;

class KadroImportMangaCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'kadro:import-manga 
                            {query : اسم المانجا أو معرف ID}
                            {--chapters=5 : الحد الأقصى للفصول المراد استيرادها}';

    /**
     * The console command description.
     */
    protected $description = 'البحث عن مانجا أو مانهوا محددة واستيراد فصولها العربية إلى المنصة';

    /**
     * Execute the console command.
     */
    public function handle(MangaAggregatorService $aggregator): int
    {
        $query = $this->argument('query');
        $chapters = (int) $this->option('chapters');

        // Check if query is directly a UUID
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $query)) {
            $this->info("📥 استيراد المانجا بالمعرف المباشر: {$query}...");
            $res = $aggregator->importSeries($query, $chapters);
            if ($res['success']) {
                $this->info("✅ {$res['message']}");
                return self::SUCCESS;
            }
            $this->error("❌ {$res['message']}");
            return self::FAILURE;
        }

        $this->info("🔍 جاري البحث عن '{$query}' في الأرشيف العربي...");
        $matches = $aggregator->searchArabicManga($query, 10);

        if (empty($matches)) {
            $this->error("لم يتم العثور على مانجا أو مانهوا تطابق '{$query}' بالترجمة العربية.");
            return self::FAILURE;
        }

        $options = array_map(fn ($m) => "{$m['title']} [{$m['format']}] ({$m['source_id']})", $matches);
        $choice = $this->choice('اختر العمل الذي ترغب في استيراده:', $options, 0);

        $selectedIndex = array_search($choice, $options);
        $selected = $matches[$selectedIndex];

        $this->info("📥 بدء استيراد '{$selected['title']}'...");
        $res = $aggregator->importSeries($selected['source_id'], $chapters);

        if ($res['success']) {
            $this->info("✅ {$res['message']}");
            return self::SUCCESS;
        }

        $this->error("❌ {$res['message']}");
        return self::FAILURE;
    }
}
