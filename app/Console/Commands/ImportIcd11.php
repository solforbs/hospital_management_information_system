<?php

namespace App\Console\Commands;

use App\Services\Reference\Icd11Importer;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Imports the WHO ICD-11 MMS classification. With no file it downloads the
 * official release from WHO. The same actions are available to platform
 * admins on Platform → ICD-11 codes.
 */
class ImportIcd11 extends Command
{
    protected $signature = 'hms:import-icd11
        {file? : Path to a SimpleTabulation .txt/.tsv (or the WHO .zip). Omit to download it from WHO}
        {--release=2026-01 : WHO release to download when no file is given (YYYY-MM)}
        {--download-only : Download the release without importing it}';

    protected $description = 'Import the full ICD-11 MMS classification for diagnosis coding';

    public function handle(Icd11Importer $importer): int
    {
        try {
            $path = $this->argument('file');

            if ($path === null) {
                $release = (string) $this->option('release');
                $this->info("Downloading ICD-11 MMS ({$release}) from WHO…");
                $path = $importer->download($release);
                $this->line("Saved to {$path}");

                if ($this->option('download-only')) {
                    return self::SUCCESS;
                }
            }

            $count = $importer->import($path);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported {$count} ICD-11 codes.");

        return self::SUCCESS;
    }
}
