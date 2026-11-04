<?php

namespace App\Console\Commands;

use App\Models\IcdCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports the WHO ICD-11 MMS "SimpleTabulation" file (tab-separated, as
 * downloaded from icd.who.int). Columns are matched by header name, so
 * minor format changes between releases are tolerated. Existing "common"
 * flags are preserved.
 */
class ImportIcd11 extends Command
{
    protected $signature = 'hms:import-icd11 {file : Path to the ICD-11 MMS SimpleTabulation .txt/.tsv file}';

    protected $description = 'Import the full ICD-11 MMS classification for diagnosis coding';

    public function handle(): int
    {
        $path = $this->argument('file');

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

    protected function flush(array $batch): int
    {
        if (! $batch) {
            return 0;
        }

        DB::table('icd_codes')->upsert($batch, ['code'], ['title', 'chapter', 'is_leaf', 'updated_at']);

        return count($batch);
    }
}
