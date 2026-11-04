<?php

namespace App\Services\Theatre;

use App\Models\Encounter;
use App\Models\Surgery;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingService;
use App\Services\Numbering\NumberSequenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Theatre booking with room double-booking prevention, WHO surgical safety
 * checklist gating before a case can start, and billing on completion.
 */
class TheatreService
{
    public const CHECKLIST = [
        'identity_confirmed' => 'Patient identity, site and procedure confirmed',
        'site_marked' => 'Surgical site marked',
        'anesthesia_check' => 'Anaesthesia machine and medication check complete',
        'pulse_oximeter' => 'Pulse oximeter on patient and functioning',
        'allergies_reviewed' => 'Known allergies reviewed',
        'airway_risk' => 'Difficult airway / aspiration risk assessed',
        'blood_loss_plan' => 'Risk of blood loss assessed and plan in place',
        'team_introduced' => 'All team members introduced by name and role',
        'antibiotics_given' => 'Antibiotic prophylaxis given (if indicated)',
    ];

    public function __construct(
        protected NumberSequenceService $numbers,
        protected BillingService $billing,
        protected AuditLogger $audit,
    ) {}

    public function schedule(Encounter $encounter, array $data): Surgery
    {
        return DB::transaction(function () use ($encounter, $data) {
            $start = Carbon::parse($data['scheduled_at']);
            $end = $start->copy()->addMinutes((int) $data['estimated_minutes']);

            if (! empty($data['theatre_room_id'])) {
                $clash = Surgery::where('theatre_room_id', $data['theatre_room_id'])
                    ->whereIn('status', ['scheduled', 'in_progress'])
                    ->lockForUpdate()
                    ->get()
                    ->first(fn (Surgery $s) => $s->scheduled_at < $end && $s->scheduled_at->copy()->addMinutes($s->estimated_minutes) > $start);

                if ($clash) {
                    throw ValidationException::withMessages(['scheduled_at' => __('Theatre is already booked for :procedure at :time.', [
                        'procedure' => $clash->procedure_name, 'time' => $clash->scheduled_at->format('H:i'),
                    ])]);
                }
            }

            $surgery = Surgery::create([
                'tenant_id' => $encounter->tenant_id,
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->id,
                'admission_id' => $encounter->admissions()->where('status', 'admitted')->value('id'),
                'theatre_room_id' => $data['theatre_room_id'] ?: null,
                'surgeon_id' => $data['surgeon_id'] ?: null,
                'anesthetist_id' => $data['anesthetist_id'] ?: null,
                'surgery_number' => $this->numbers->next('SUR', $encounter->tenant_id),
                'procedure_name' => $data['procedure_name'],
                'urgency' => $data['urgency'],
                'anesthesia_type' => $data['anesthesia_type'] ?: null,
                'scheduled_at' => $start,
                'estimated_minutes' => (int) $data['estimated_minutes'],
                'fee' => $data['fee'] ?? 0,
                'status' => 'scheduled',
                'checklist' => [],
                'booked_by' => Auth::id(),
            ]);

            $this->audit->log('theatre.scheduled', $surgery);

            return $surgery;
        });
    }

    public function updateChecklist(Surgery $surgery, array $checked, bool $consent): Surgery
    {
        $surgery->update([
            'checklist' => array_values(array_intersect(array_keys(self::CHECKLIST), $checked)),
            'consent_obtained' => $consent,
        ]);

        return $surgery;
    }

    public function start(Surgery $surgery): Surgery
    {
        if ($surgery->status !== 'scheduled') {
            throw ValidationException::withMessages(['status' => __('Only a scheduled case can be started.')]);
        }

        if (! $surgery->consent_obtained) {
            throw ValidationException::withMessages(['status' => __('Informed consent must be recorded before starting.')]);
        }

        $missing = array_diff(array_keys(self::CHECKLIST), $surgery->checklist ?? []);
        if ($missing) {
            throw ValidationException::withMessages(['status' => __('Complete the surgical safety checklist first (:count item(s) outstanding).', ['count' => count($missing)])]);
        }

        $surgery->update(['status' => 'in_progress', 'started_at' => now()]);
        $this->audit->log('theatre.started', $surgery);

        return $surgery;
    }

    public function complete(Surgery $surgery, string $notes, ?string $complications): Surgery
    {
        if ($surgery->status !== 'in_progress') {
            throw ValidationException::withMessages(['status' => __('Only a case in progress can be completed.')]);
        }

        return DB::transaction(function () use ($surgery, $notes, $complications) {
            $surgery->update([
                'status' => 'completed',
                'ended_at' => now(),
                'operative_notes' => $notes,
                'complications' => $complications,
            ]);

            if ((float) $surgery->fee > 0) {
                $this->billing->charge($surgery->encounter, 'theatre', __('Surgery: :procedure', ['procedure' => $surgery->procedure_name]), (float) $surgery->fee, 1, $surgery);
            }

            $this->audit->log('theatre.completed', $surgery);

            return $surgery;
        });
    }

    public function cancel(Surgery $surgery, string $reason): Surgery
    {
        if (! in_array($surgery->status, ['scheduled'], true)) {
            throw ValidationException::withMessages(['status' => __('Only a scheduled case can be cancelled.')]);
        }

        $surgery->update(['status' => 'cancelled', 'cancellation_reason' => $reason]);
        $this->audit->log('theatre.cancelled', $surgery, ['reason' => $reason]);

        return $surgery;
    }
}
