<?php

namespace Database\Seeders;

use App\Models\Bed;
use App\Models\InsuranceProvider;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\RadiologyExam;
use App\Models\ServiceItem;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\TheatreRoom;
use App\Models\Ward;
use Illuminate\Database\Seeder;

/**
 * Starter catalogs for a newly provisioned tenant: tariffs, lab tests,
 * imaging exams, a basic essential-medicines formulary with opening
 * stock, wards with beds, a theatre and common payers. Every value is
 * editable by the tenant afterwards; nothing here is hard-coded into
 * business logic except the auto-charged tariff codes.
 */
class TenantCatalogSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::all()->each(fn (Tenant $t) => $this->seedFor($t));
    }

    public function seedFor(Tenant $tenant): void
    {
        $t = ['tenant_id' => $tenant->id];
        $facilityId = $tenant->facilities()->withoutGlobalScope('tenant')->orderByDesc('is_main')->value('id');

        foreach ([
            ['REG-FEE', 'Registration / file fee', 'registration', 200],
            ['ER-FEE', 'Emergency attendance fee', 'emergency', 1000],
            ['CONSULT', 'General consultation', 'consultation', 1000],
            ['CONSULT-SPEC', 'Specialist consultation', 'consultation', 2500],
            ['BED-DAY', 'General ward bed (per day)', 'bed', 2500],
            ['NURS-DAY', 'Nursing care (per day)', 'nursing', 1000],
            ['DRESSING', 'Wound dressing', 'procedure', 500],
            ['SUTURE', 'Suturing (minor)', 'procedure', 1500],
            ['IV-CANN', 'IV cannulation', 'procedure', 300],
            ['NEB', 'Nebulisation', 'procedure', 500],
            ['ECG-SVC', 'ECG recording', 'procedure', 1500],
        ] as [$code, $name, $category, $price]) {
            ServiceItem::withoutGlobalScope('tenant')->firstOrCreate($t + ['code' => $code], compact('name', 'category', 'price'));
        }

        foreach ([
            ['HB', 'Haemoglobin', 'Haematology', 'blood', 'g/dL', 12, 17.5, 'numeric', 300],
            ['FBC', 'Full blood count', 'Haematology', 'blood', null, null, null, 'text', 800],
            ['WBC', 'White cell count', 'Haematology', 'blood', '10^9/L', 4, 11, 'numeric', 300],
            ['PLT', 'Platelet count', 'Haematology', 'blood', '10^9/L', 150, 400, 'numeric', 300],
            ['ESR', 'ESR', 'Haematology', 'blood', 'mm/hr', 0, 20, 'numeric', 300],
            ['BS-MPS', 'Malaria parasites (BS for MPS)', 'Parasitology', 'blood', null, null, null, 'text', 300],
            ['MRDT', 'Malaria rapid test', 'Parasitology', 'blood', null, null, null, 'text', 400],
            ['RBS', 'Random blood sugar', 'Biochemistry', 'blood', 'mmol/L', 3.9, 7.8, 'numeric', 200],
            ['FBS', 'Fasting blood sugar', 'Biochemistry', 'blood', 'mmol/L', 3.9, 5.6, 'numeric', 250],
            ['HBA1C', 'HbA1c', 'Biochemistry', 'blood', '%', 4, 5.6, 'numeric', 2000],
            ['CREAT', 'Creatinine', 'Biochemistry', 'blood', 'µmol/L', 60, 110, 'numeric', 600],
            ['UREA', 'Urea', 'Biochemistry', 'blood', 'mmol/L', 2.5, 7.8, 'numeric', 600],
            ['NA', 'Sodium', 'Biochemistry', 'blood', 'mmol/L', 135, 145, 'numeric', 500],
            ['K', 'Potassium', 'Biochemistry', 'blood', 'mmol/L', 3.5, 5.1, 'numeric', 500],
            ['ALT', 'ALT', 'Biochemistry', 'blood', 'U/L', 7, 56, 'numeric', 600],
            ['URINAL', 'Urinalysis', 'Microbiology', 'urine', null, null, null, 'text', 300],
            ['STOOL', 'Stool microscopy', 'Microbiology', 'stool', null, null, null, 'text', 300],
            ['WIDAL', 'Widal test', 'Serology', 'blood', null, null, null, 'text', 500],
            ['HPYL', 'H. pylori antigen', 'Serology', 'stool', null, null, null, 'text', 1200],
            ['HIV', 'HIV rapid test', 'Serology', 'blood', null, null, null, 'text', 0],
            ['PDT', 'Pregnancy test (urine)', 'Serology', 'urine', null, null, null, 'text', 200],
            ['GXM', 'Blood group & crossmatch', 'Blood bank', 'blood', null, null, null, 'text', 1000],
        ] as [$code, $name, $category, $specimen, $unit, $low, $high, $type, $price]) {
            LabTest::withoutGlobalScope('tenant')->firstOrCreate($t + ['code' => $code], [
                'name' => $name, 'category' => $category, 'specimen_type' => $specimen, 'unit' => $unit,
                'reference_low' => $low, 'reference_high' => $high, 'result_type' => $type, 'price' => $price,
                'reference_text' => $type === 'text' ? ($code === 'HIV' || $code === 'MRDT' || $code === 'PDT' ? 'Negative' : null) : null,
            ]);
        }

        foreach ([
            ['XR-CHEST', 'Chest X-ray (PA)', 'xray', 1500, false, null],
            ['XR-LIMB', 'X-ray limb', 'xray', 1500, false, null],
            ['XR-SPINE', 'X-ray lumbar spine', 'xray', 2000, false, null],
            ['US-ABD', 'Abdominal ultrasound', 'ultrasound', 2500, true, 'Fast for 6 hours'],
            ['US-PELV', 'Pelvic ultrasound', 'ultrasound', 2500, true, 'Full bladder'],
            ['US-OBS', 'Obstetric ultrasound', 'ultrasound', 2500, false, null],
            ['CT-HEAD', 'CT head (non-contrast)', 'ct', 9000, false, null],
            ['CT-ABD', 'CT abdomen (contrast)', 'ct', 15000, true, 'Fast 4 hours; creatinine required'],
            ['MRI-BRAIN', 'MRI brain', 'mri', 25000, false, null],
            ['ECHO', 'Echocardiogram', 'echo', 5000, false, null],
        ] as [$code, $name, $modality, $price, $prep, $instructions]) {
            RadiologyExam::withoutGlobalScope('tenant')->firstOrCreate($t + ['code' => $code], [
                'name' => $name, 'modality' => $modality, 'price' => $price, 'requires_preparation' => $prep, 'preparation_instructions' => $instructions,
            ]);
        }

        foreach ([
            ['PCM500', 'Paracetamol', 'Panadol', 'tablet', '500mg', 'tablet', 5, 500, false],
            ['IBU400', 'Ibuprofen', 'Brufen', 'tablet', '400mg', 'tablet', 8, 300, false],
            ['AMX500', 'Amoxicillin', 'Amoxil', 'capsule', '500mg', 'capsule', 10, 300, false],
            ['AUG625', 'Amoxicillin/Clavulanate', 'Augmentin', 'tablet', '625mg', 'tablet', 60, 200, false],
            ['CIP500', 'Ciprofloxacin', 'Ciprobay', 'tablet', '500mg', 'tablet', 20, 200, false],
            ['MET400', 'Metronidazole', 'Flagyl', 'tablet', '400mg', 'tablet', 5, 300, false],
            ['AL20', 'Artemether/Lumefantrine', 'Coartem', 'tablet', '20/120mg', 'tablet', 25, 240, false],
            ['ART60', 'Artesunate injection', null, 'injection', '60mg', 'vial', 450, 50, false],
            ['OMP20', 'Omeprazole', 'Losec', 'capsule', '20mg', 'capsule', 10, 300, false],
            ['MTF500', 'Metformin', 'Glucophage', 'tablet', '500mg', 'tablet', 5, 500, false],
            ['AML5', 'Amlodipine', 'Norvasc', 'tablet', '5mg', 'tablet', 6, 500, false],
            ['ORS', 'Oral rehydration salts', null, 'sachet', '20.5g', 'sachet', 30, 200, false],
            ['NS500', 'Normal saline 0.9%', null, 'infusion', '500ml', 'bag', 120, 100, false],
            ['RL500', "Ringer's lactate", null, 'infusion', '500ml', 'bag', 130, 100, false],
            ['CTX1G', 'Ceftriaxone injection', 'Rocephin', 'injection', '1g', 'vial', 250, 100, false],
            ['SALB', 'Salbutamol inhaler', 'Ventolin', 'inhaler', '100mcg', 'inhaler', 450, 30, false],
            ['MORPH10', 'Morphine injection', null, 'injection', '10mg/ml', 'ampoule', 300, 20, true],
            ['DICLO75', 'Diclofenac injection', 'Voltaren', 'injection', '75mg', 'ampoule', 50, 100, false],
        ] as [$code, $generic, $brand, $form, $strength, $unit, $price, $openingStock, $controlled]) {
            $medicine = Medicine::withoutGlobalScope('tenant')->firstOrCreate($t + ['code' => $code], [
                'generic_name' => $generic, 'brand_name' => $brand, 'form' => $form, 'strength' => $strength,
                'unit' => $unit, 'unit_price' => $price, 'reorder_level' => (int) ($openingStock / 5), 'is_controlled' => $controlled,
            ]);

            if (! MedicineBatch::withoutGlobalScope('tenant')->where('medicine_id', $medicine->id)->exists()) {
                $batch = MedicineBatch::withoutGlobalScope('tenant')->create($t + [
                    'medicine_id' => $medicine->id, 'batch_number' => 'OPEN-'.$code, 'expiry_date' => now()->addMonths(18)->toDateString(),
                    'quantity_received' => $openingStock, 'quantity_available' => $openingStock, 'unit_cost' => round($price * 0.6, 2), 'supplier' => 'Opening stock',
                ]);
                StockMovement::withoutGlobalScope('tenant')->create($t + [
                    'medicine_id' => $medicine->id, 'medicine_batch_id' => $batch->id, 'type' => 'receipt',
                    'quantity' => $openingStock, 'reason' => 'Opening stock', 'created_at' => now(),
                ]);
            }
        }

        if ($facilityId) {
            foreach ([
                ['Male Medical', 'MMW', 'general', 'male', 2500, 8],
                ['Female Medical', 'FMW', 'general', 'female', 2500, 8],
                ['Paediatric', 'PAED', 'paediatric', 'mixed', 2000, 6],
                ['Maternity', 'MAT', 'maternity', 'female', 3000, 6],
                ['Surgical', 'SURG', 'surgical', 'mixed', 3000, 6],
                ['ICU', 'ICU', 'icu', 'mixed', 15000, 4],
            ] as [$name, $code, $type, $gender, $rate, $beds]) {
                $ward = Ward::withoutGlobalScope('tenant')->firstOrCreate($t + ['code' => $code], [
                    'facility_id' => $facilityId, 'name' => $name, 'type' => $type, 'gender' => $gender, 'daily_rate' => $rate,
                ]);

                for ($i = 1; $i <= $beds; $i++) {
                    Bed::withoutGlobalScope('tenant')->firstOrCreate($t + ['ward_id' => $ward->id, 'bed_number' => $code.'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)], ['status' => 'available']);
                }
            }

            foreach (['Theatre 1', 'Theatre 2'] as $room) {
                TheatreRoom::withoutGlobalScope('tenant')->firstOrCreate($t + ['facility_id' => $facilityId, 'name' => $room]);
            }
        }

        foreach ([
            ['Social Health Authority', 'SHA', 'sha', true],
            ['AAR Insurance', 'AAR', 'private', true],
            ['Jubilee Health', 'JUBILEE', 'private', false],
            ['Britam', 'BRITAM', 'private', false],
        ] as [$name, $code, $type, $preauth]) {
            InsuranceProvider::withoutGlobalScope('tenant')->firstOrCreate($t + ['code' => $code], ['name' => $name, 'type' => $type, 'requires_preauthorization' => $preauth]);
        }
    }
}
