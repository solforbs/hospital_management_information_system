<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalCertificate extends Model
{
    use BelongsToTenant;

    public const TYPES = ['sick_leave' => 'Sick leave / sick note', 'fitness' => 'Medical fitness', 'attendance' => 'Attendance (seen at hospital)'];

    protected $fillable = ['tenant_id', 'patient_id', 'encounter_id', 'certificate_number', 'type', 'from_date', 'to_date', 'days', 'diagnosis_text', 'remarks', 'issued_by'];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
