<?php

namespace App\Console\Commands;

use App\Models\IcdCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Imports the WHO ICD-11 MMS "SimpleTabulation" file (tab-separated, as
 * downloaded from icd.who.int). Columns are matched by header name, so
 * minor format changes between releases are tolerated. Existing "common"
 * flags are preserved.
 */
class ImportIcd11 extends Command
{
    protected $signature = 'hms:import-icd11
        {file? : Path to a SimpleTabulation .txt/.tsv (or the WHO .zip). Omit to download it from WHO}
        {--release=2026-01 : WHO release to download when no file is given (YYYY-MM)}';

    protected $description = 'Import the full ICD-11 MMS classification for diagnosis coding';

    /** Official WHO release files (icd.who.int → Info → Spreadsheet file). */
    protected const WHO_URL = 'https://icdcdn.who.int/static/releasefiles/%s/SimpleTabulation-ICD-11-MMS-en.zip';

    public function handle(): int
    {
        $path = $this->argument('file') ?? $this->download((string) $this->option('release'));

        if ($path === null) {
            return self::FAILURE;
        }

        if (str_ends_with(strtolower($path), '.zip')) {
            $path = $this->extract($path);
            if ($path === null) {
                return self::FAILURE;
            }
        }

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, "\t");
        $header = array_map(fn ($h) => strtolower(trim((string) $h, "\xEF\xBB\xBF \"")), $header ?: []);
        $col = fn (string $name) => array_search($name, $header, true);

        [$codeCol, $titleCol, $kindCol, $chapterCol, $leafCol] = [$col('code'), $col('title'), $col('classkind'), $col('chapterno'), $col('isleaf')];

        if ($codeCol === false || $titleCol === false) {
            $this->error('Unrecognised file: expected "Code" and "Title" columns (ICD-11 SimpleTabulation).');

            return self::FAILURE;
        }

        $common = IcdCode::where('is_common', true)->pluck('code')->flip();
        $batch = [];
        $count = 0;
        $now = now();

        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            $code = trim((string) ($row[$codeCol] ?? ''));
            $kind = $kindCol !== false ? strtolower(trim((string) ($row[$kindCol] ?? ''))) : 'category';

            if ($code === '' || ($kind !== '' && $kind !== 'category')) {
                continue; // chapters and blocks have no code
            }

            $title = trim(ltrim((string) ($row[$titleCol] ?? ''), "- \t"));

            $batch[] = [
                'code' => strtoupper($code),
                'title' => mb_substr($title, 0, 500),
                'chapter' => $chapterCol !== false ? mb_substr(trim((string) ($row[$chapterCol] ?? '')), 0, 4) : null,
                'system' => 'icd11',
                'is_leaf' => $leafCol !== false ? in_array(strtolower(trim((string) ($row[$leafCol] ?? 'true'))), ['true', '1'], true) : true,
                'is_common' => $common->has(strtoupper($code)),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                $count += $this->flush($batch);
                $batch = [];
            }
        }

        $count += $this->flush($batch);
        fclose($handle);

        $this->info("Imported {$count} ICD-11 codes.");

        return self::SUCCESS;
    }

    protected function download(string $release): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $release)) {
            $this->error('--release must look like 2026-01.');

            return null;
        }

        $url = sprintf(self::WHO_URL, $release);
        $zip = storage_path("app/icd11/SimpleTabulation-{$release}.zip");
        @mkdir(dirname($zip), 0775, true);

        if (! is_file($zip)) {
            $this->info("Downloading ICD-11 MMS ({$release}) from WHO…");
            $response = Http::timeout(300)->withOptions(['sink' => $zip])->get($url);

            if (! $response->successful()) {
                @unlink($zip);
                $this->error("Download failed (HTTP {$response->status()}): {$url}");
                $this->line('Download it manually from https://icd.who.int/browse (Info → Spreadsheet file) and pass the path.');

                return null;
            }
        }

        return $zip;
    }

    protected function extract(string $zipPath): ?string
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->error("Cannot open {$zipPath}");

            return null;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/SimpleTabulation.*\.txt$/i', basename($name))) {
                $target = dirname($zipPath).'/'.basename($name);
                file_put_contents($target, $zip->getFromIndex($i));
                $zip->close();

                return $target;
            }
        }

        $zip->close();
        $this->error('The zip does not contain a SimpleTabulation .txt file.');

        return null;
    }

    protected function flush(array $batch): int
    {
        if (! $batch) {
            return 0;
        }

        DB::table('icd_codes')->upsert($batch, ['code'], ['title', 'chapter', 'is_leaf', 'updated_at']);

        return count($batch);
    }
}
