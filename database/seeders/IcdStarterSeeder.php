<?php

namespace Database\Seeders;

use App\Models\IcdCode;
use Illuminate\Database\Seeder;

/**
 * Starter ICD-11 MMS list of conditions common in Kenyan outpatient and
 * inpatient practice, so coding works out of the box. Load the complete
 * WHO classification with: php artisan hms:import-icd11 (downloads the WHO release; or pass a file)
 * (download from icd.who.int → ICD-11 MMS → Spreadsheet/Tabulation).
 */
class IcdStarterSeeder extends Seeder
{
    public const CODES = [
        ['1A00', 'Cholera', '01'],
        ['1A03', 'Intestinal infections due to Escherichia coli', '01'],
        ['1A07', 'Typhoid fever', '01'],
        ['1A40', 'Gastroenteritis or colitis without specification of infectious agent', '01'],
        ['1B10', 'Tuberculosis of the respiratory system', '01'],
        ['1B70', 'Bacterial cellulitis, erysipelas or lymphangitis', '01'],
        ['1C62', 'HIV disease', '01'],
        ['1F28', 'Dermatophytosis', '01'],
        ['1F40', 'Malaria due to Plasmodium falciparum', '01'],
        ['1F4Z', 'Malaria, unspecified', '01'],
        ['3A00', 'Iron deficiency anaemia', '03'],
        ['3A51', 'Sickle cell disorders', '03'],
        ['5A10', 'Type 1 diabetes mellitus', '05'],
        ['5A11', 'Type 2 diabetes mellitus', '05'],
        ['6A20', 'Schizophrenia', '06'],
        ['6A70', 'Single episode depressive disorder', '06'],
        ['6B00', 'Generalised anxiety disorder', '06'],
        ['6C40', 'Disorders due to use of alcohol', '06'],
        ['8A6Z', 'Epilepsy or seizures, unspecified', '08'],
        ['8A80', 'Migraine', '08'],
        ['8B00', 'Intracerebral haemorrhage', '08'],
        ['8B11', 'Cerebral ischaemic stroke', '08'],
        ['9A60', 'Conjunctivitis', '09'],
        ['9B10', 'Age-related cataract', '09'],
        ['BA00', 'Essential hypertension', '11'],
        ['BA40', 'Angina pectoris', '11'],
        ['BA41', 'Acute myocardial infarction', '11'],
        ['BD10', 'Congestive heart failure', '11'],
        ['CA00', 'Acute nasopharyngitis', '12'],
        ['CA02', 'Acute pharyngitis', '12'],
        ['CA03', 'Acute tonsillitis', '12'],
        ['CA22', 'Chronic obstructive pulmonary disease', '12'],
        ['CA23', 'Asthma', '12'],
        ['CA40', 'Pneumonia', '12'],
        ['CA40.0', 'Bacterial pneumonia', '12'],
        ['DA42', 'Gastritis', '13'],
        ['DA60', 'Gastric ulcer', '13'],
        ['DA63', 'Duodenal ulcer', '13'],
        ['DB10', 'Appendicitis', '13'],
        ['EA80', 'Atopic eczema', '14'],
        ['EA90', 'Psoriasis', '14'],
        ['FA00', 'Osteoarthritis of hip', '15'],
        ['FA01', 'Osteoarthritis of knee', '15'],
        ['FA20', 'Rheumatoid arthritis', '15'],
        ['GC08', 'Urinary tract infection, site not specified', '16'],
        ['JA00', 'Spontaneous abortion', '18'],
        ['JA24', 'Pre-eclampsia', '18'],
        ['JA25', 'Eclampsia', '18'],
        ['MD12', 'Cough', '21'],
        ['MD30', 'Chest pain', '21'],
        ['ME05.1', 'Diarrhoea', '21'],
        ['ME84.2', 'Low back pain', '21'],
        ['MG26', 'Fever of other or unknown origin', '21'],
        ['QA00', 'General examination', '24'],
    ];

    public function run(): void
    {
        foreach (self::CODES as [$code, $title, $chapter]) {
            IcdCode::updateOrCreate(['code' => $code], ['title' => $title, 'chapter' => $chapter, 'system' => 'icd11', 'is_leaf' => true, 'is_common' => true]);
        }
    }
}
