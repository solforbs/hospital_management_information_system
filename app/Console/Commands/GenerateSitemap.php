<?php

namespace App\Console\Commands;

use App\Support\Seo;
use Illuminate\Console\Command;

class GenerateSitemap extends Command
{
    protected $signature = 'hms:generate-sitemap';

    protected $description = 'Regenerate sitemap.xml from the public, indexable pages';

    public function handle(): int
    {
        $count = Seo::generateSitemap();
        $this->info("Sitemap generated with {$count} URL(s): ".url('/sitemap.xml'));

        return self::SUCCESS;
    }
}
