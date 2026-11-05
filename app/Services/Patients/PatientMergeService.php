<?php

namespace App\Services\Patients;

use App\Models\Patient;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Merges a duplicate patient record into the surviving record. Every
 * clinical, financial and administrative row pointing at the duplicate is
 * re-pointed (discovered from the schema, so new modules are covered
 * automatically); blank demographics on the survivor are filled from the
 * duplicate; the duplicate is kept as a tombstone (merged_into_id) so old
 * MRNs still resolve. The action is audited with the full move counts.
 */
class PatientMergeService
{
    /** Columns that reference patients.id. */
    protected const COLUMNS = ['patient_id', 'mother_patient_id', 'baby_patient_id'];

    /** Tables where a patient may own at most one row per key: duplicate rows on the merged record are dropped. */
    protected const UNIQUE_PER_PATIENT = [
        'immunizations' => 'vaccine',
        'hei_enrolments' => null,
    ];

    /** Demographic fields copied from the duplicate when the survivor's is blank. */
    protected const FILL = ['middle_name', 'date_of_birth', 'national_id', 'cr_number', 'sha_number', 'identification_type', 'phone', 'email', 'address', 'county', 'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship'];

    public function __construct(protected AuditLogger $audit) {}

    public function merge(Patient $keep, Patient $duplicate, string $reason): array
    {
        if ($keep->id === $duplicate->id) {
            throw ValidationException::withMessages(['duplicate' => __('Choose two different records.')]);
        }

        if ($keep->tenant_id !== $duplicate->tenant_id) {
            throw ValidationException::withMessages(['duplicate' => __('Records belong to different hospitals.')]);
        }

        if ($duplicate->merged_into_id || $keep->merged_into_id) {
            throw ValidationException::withMessages(['duplicate' => __('One of these records has already been merged.')]);
        }

        if ($keep->activeEncounter() && $duplicate->activeEncounter()) {
            throw ValidationException::withMessages(['duplicate' => __('Both records have an open visit. Complete or cancel one first.')]);
        }

        if ($keep->sex !== $duplicate->sex && $keep->sex !== 'unknown' && $duplicate->sex !== 'unknown') {
            throw ValidationException::withMessages(['duplicate' => __('The records have different sexes — they are probably different people.')]);
        }

        return DB::transaction(function () use ($keep, $duplicate, $reason) {
            $moved = [];

            foreach ($this->referencingTables() as $table => $columns) {
                if ($key = self::UNIQUE_PER_PATIENT[$table] ?? false) {
                    $this->dropConflicts($table, $key, $keep->id, $duplicate->id);
                } elseif (array_key_exists($table, self::UNIQUE_PER_PATIENT)) {
                    if (DB::table($table)->where('patient_id', $keep->id)->exists()) {
                        DB::table($table)->where('patient_id', $duplicate->id)->delete();
                    }
                }

                foreach ($columns as $column) {
                    $n = DB::table($table)->where($column, $duplicate->id)->update([$column => $keep->id]);
                    if ($n) {
                        $moved["{$table}.{$column}"] = $n;
                    }
                }
            }

            $fill = [];
            foreach (self::FILL as $field) {
                if (blank($keep->{$field}) && filled($duplicate->{$field})) {
                    $fill[$field] = $duplicate->{$field};
                }
            }

            // Unique identifiers can only live on one record.
            $duplicate->forceFill(array_fill_keys(array_keys(array_intersect_key($fill, array_flip(['national_id', 'cr_number', 'sha_number']))), null))->save();
            $keep->fill($fill)->save();

            $duplicate->forceFill(['merged_into_id' => $keep->id, 'merged_at' => now()])->save();

            $this->audit->log('patients.merged', $keep, [
                'kept' => $keep->mrn, 'merged' => $duplicate->mrn, 'reason' => $reason, 'moved' => $moved, 'filled' => array_keys($fill), 'by' => Auth::id(),
            ]);

            return $moved;
        });
    }

    /** @return array<string, array<int, string>> table => patient-referencing columns */
    protected function referencingTables(): array
    {
        $out = [];

        foreach (Schema::getTables() as $t) {
            $name = $t['name'];
            if (in_array($name, ['patients', 'patient_number_sequences', 'migrations'], true)) {
                continue;
            }

            $cols = array_values(array_intersect(self::COLUMNS, Schema::getColumnListing($name)));
            if ($cols) {
                $out[$name] = $cols;
            }
        }

        return $out;
    }

    protected function dropConflicts(string $table, string $key, int $keepId, int $dupId): void
    {
        $existing = DB::table($table)->where('patient_id', $keepId)->pluck($key);
        DB::table($table)->where('patient_id', $dupId)->whereIn($key, $existing)->delete();
    }
}
