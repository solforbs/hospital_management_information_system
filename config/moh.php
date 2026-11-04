<?php

/*
 * KHIS (DHIS2) identifiers for the MOH monthly summaries.
 *
 * Each report row has a stable key (see App\Services\Reports\MohReportService).
 * Map keys to the KHIS data element UIDs (and category option combos when
 * your KHIS instance uses them) from the KHIS metadata, e.g.
 *   'data_elements' => ['717' => ['opd_total' => 'aBcDeFgHiJk', ...]]
 * Unmapped rows are exported with a readable placeholder id so the file can
 * be reviewed before import. The facility's KHIS org unit UID is set on
 * Administration → Facilities (KHIS org unit).
 */
return [
    'data_sets' => [
        '705A' => env('KHIS_DS_705A'),
        '705B' => env('KHIS_DS_705B'),
        '717' => env('KHIS_DS_717'),
        '405' => env('KHIS_DS_405'),
        '333' => env('KHIS_DS_333'),
        '406' => env('KHIS_DS_406'),
    ],

    'data_elements' => [
        '705A' => [],
        '705B' => [],
        '717' => [],
        '405' => [],
        '333' => [],
        '406' => [],
    ],
];
