<?php

namespace App\Support;

/**
 * Starter code mappings for common analyser types. Codes follow the usual
 * vendor conventions (Mindray, Sysmex, Rayto, URIT…) but every model differs
 * slightly, so mappings stay editable. Rows are added only for tests that
 * exist in the hospital's catalog.
 *
 * Each row: [analyser code, HMIS test code, component name or null].
 */
class AnalyserPresets
{
    public static function all(): array
    {
        $fbc3 = [['WBC', 'WBC'], ['LYM#', 'Lymphocytes #'], ['MID#', 'Mid cells #'], ['GRAN#', 'Granulocytes #'], ['LYM%', 'Lymphocytes %'], ['MID%', 'Mid cells %'], ['GRAN%', 'Granulocytes %'],
            ['RBC', 'RBC'], ['HGB', 'Haemoglobin'], ['HCT', 'Haematocrit'], ['MCV', 'MCV'], ['MCH', 'MCH'], ['MCHC', 'MCHC'], ['RDW-CV', 'RDW-CV'], ['PLT', 'Platelets'], ['MPV', 'MPV']];
        $fbc5 = [['WBC', 'WBC'], ['NEU#', 'Neutrophils #'], ['LYM#', 'Lymphocytes #'], ['MON#', 'Monocytes #'], ['EOS#', 'Eosinophils #'], ['BAS#', 'Basophils #'],
            ['NEU%', 'Neutrophils %'], ['LYM%', 'Lymphocytes %'], ['MON%', 'Monocytes %'], ['EOS%', 'Eosinophils %'], ['BAS%', 'Basophils %'],
            ['RBC', 'RBC'], ['HGB', 'Haemoglobin'], ['HCT', 'Haematocrit'], ['MCV', 'MCV'], ['MCH', 'MCH'], ['MCHC', 'MCHC'], ['RDW-CV', 'RDW-CV'], ['PLT', 'Platelets'], ['MPV', 'MPV']];

        // Individually ordered Hb / WBC / platelets also come from the haematology analyser.
        $singles = [['HGB', 'HB', null], ['WBC', 'WBC', null], ['PLT', 'PLT', null]];

        return [
            'haematology-3' => [
                'label' => 'Haematology analyser — 3-part differential (FBC)',
                'protocol' => 'hl7',
                'rows' => array_merge(array_map(fn ($r) => [$r[0], 'FBC', $r[1]], $fbc3), $singles),
            ],
            'haematology-5' => [
                'label' => 'Haematology analyser — 5-part differential (FBC)',
                'protocol' => 'hl7',
                'rows' => array_merge(array_map(fn ($r) => [$r[0], 'FBC', $r[1]], $fbc5), $singles),
            ],
            'chemistry' => [
                'label' => 'Clinical chemistry analyser',
                'protocol' => 'astm',
                'rows' => [['GLU', 'RBS', null], ['GLU', 'FBS', null], ['CREA', 'CREAT', null], ['UREA', 'UREA', null], ['NA', 'NA', null], ['K', 'K', null], ['ALT', 'ALT', null], ['HBA1C', 'HBA1C', null]],
            ],
        ];
    }
}
