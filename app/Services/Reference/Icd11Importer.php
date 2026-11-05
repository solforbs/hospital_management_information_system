<?php

namespace App\Services\Reference;

use App\Models\IcdCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Downloads, stores and imports the WHO ICD-11 MMS "SimpleTabulation" file.
 *
 * Files are kept in storage/app/icd11 so a release can be downloaded once
 * (or uploaded on an offline server) and imported whenever convenient.
 * Columns are matched by header name, so minor format changes between
 * releases are tolerated. Re-importing updates titles; "common" flags are
 * preserved.
 */
class Icd11Importer
{
    /** Official WHO release files (icd.who.int → Info → Spreadsheet file). */
    public const WHO_URL = 'https://icdcdn.who.int/static/releasefiles/%s/SimpleTabulation-ICD-11-MMS-en.zip';

    public function directory(): string
    {
        $dir = config('hms.icd11_path') ?: storage_path('app/icd11');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** Downloads a release zip from WHO (kept for later import). Returns its path. */
    public function download(string $release): string
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $release)) {
            throw new RuntimeException('The release must look like 2026-01.');
        }

        @set_time_limit(600);
        $path = $this->directory()."/SimpleTabulation-{$release}.zip";
        $partial = $path.'.part';

        $response = Http::timeout(300)->withOptions(['sink' => $partial])->get(sprintf(self::WHO_URL, $release));

        if (! $response->successful()) {
            @unlink($partial);

            throw new RuntimeException("WHO returned HTTP {$response->status()} for release {$release}. Check the release name on icd.who.int.");
        }

        rename($partial, $path);

        return $path;
    }

    /** Stored release files, newest first. */
    public function files(): array
    {
        $files = glob($this->directory().'/*.{zip,txt,tsv}', GLOB_BRACE) ?: [];

        return collect($files)
            ->reject(fn ($f) => str_ends_with($f, '.part') || basename($f) === 'SimpleTabulation-ICD-11-MMS-en.txt')
            ->map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'modified' => filemtime($f)])
            ->sortByDesc('modified')
            ->values()
            ->all();
    }

    public function pathFor(string $name): string
    {
        $path = $this->directory().'/'.basename($name);

        if (! is_file($path)) {
            throw new RuntimeException("File {$name} not found.");
        }

        return $path;
    }

    /** Imports a .zip/.txt/.tsv and returns the number of codes written. */
    public function import(string $path): int
    {
        @set_time_limit(600);
        $source = $path;

        if (str_ends_with(strtolower($path), '.zip')) {
            $path = $this->extract($path);
        }

        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read {$path}");
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, "\t");
        $header = array_map(fn ($h) => strtolower(trim((string) $h, "\xEF\xBB\xBF \"")), $header ?: []);
        $col = fn (string $name) => array_search($name, $header, true);

        [$codeCol, $titleCol, $kindCol, $chapterCol, $leafCol] = [$col('code'), $col('title'), $col('classkind'), $col('chapterno'), $col('isleaf')];

        if ($codeCol === false || $titleCol === false) {
            fclose($handle);

            throw new RuntimeException('Unrecognised file: expected "Code" and "Title" columns (WHO ICD-11 SimpleTabulation).');
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

        if ($count === 0) {
            throw new RuntimeException('No ICD-11 codes were found in the file.');
        }

        Cache::forever('icd11.last_import', [
            'file' => basename($source),
            'count' => $count,
            'at' => now()->toIso8601String(),
            'by' => auth()->user()?->name ?? 'command line',
        ]);

        return $count;
    }

    protected function extract(string $zipPath): string
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Cannot open the zip file.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/SimpleTabulation.*\.txt$/i', basename($name))) {
                $target = $this->directory().'/SimpleTabulation-ICD-11-MMS-en.txt';
                file_put_contents($target, $zip->getFromIndex($i));
                $zip->close();

                return $target;
            }
        }

        $zip->close();

        throw new RuntimeException('The zip does not contain a SimpleTabulation .txt file.');
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
