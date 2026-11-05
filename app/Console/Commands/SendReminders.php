<?php

namespace App\Console\Commands;

use App\Models\AncVisit;
use App\Models\Appointment;
use App\Models\Immunization;
use App\Models\IntegrationCredential;
use App\Models\Patient;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Services\Messaging\SmsService;
use App\Services\Tenancy\TenantContext;
use App\Support\KepiSchedule;
use Illuminate\Console\Command;

/**
 * Daily SMS reminders (scheduled 08:00): tomorrow's appointments, ANC
 * visits due tomorrow, and child vaccines due tomorrow. A patient gets at
 * most one reminder per purpose per day.
 */
class SendReminders extends Command
{
    protected $signature = 'hms:send-reminders';

    protected $description = 'Send SMS reminders for appointments, ANC visits and immunisations due tomorrow';

    public function handle(TenantContext $context, SmsService $sms): int
    {
        $tomorrow = now()->addDay()->toDateString();

        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            $context->set($tenant);

            if (! IntegrationCredential::for('sms')) {
                continue;
            }

            $name = $tenant->displayName();
            $sent = 0;

            $send = function (?Patient $p, string $purpose, string $text) use ($sms, &$sent) {
                if (! $p?->phone || $p->deceased_at) {
                    return;
                }
                if (SmsMessage::where('patient_id', $p->id)->where('purpose', $purpose)->whereDate('created_at', today())->exists()) {
                    return;
                }
                $sms->send($p->phone, $text, $purpose, $p);
                $sent++;
            };

            foreach (Appointment::with('patient')->whereDate('scheduled_at', $tomorrow)->whereIn('status', ['booked', 'confirmed'])->get() as $a) {
                $send($a->patient, 'appointment_reminder', __('Reminder: you have an appointment at :h tomorrow :t. Reply or call to reschedule.', ['h' => $name, 't' => $a->scheduled_at->format('d M H:i')]));
            }

            foreach (AncVisit::with('pregnancy.patient')->where('next_visit_date', $tomorrow)->get() as $v) {
                if ($v->pregnancy?->status === 'active') {
                    $send($v->pregnancy->patient, 'anc_due', __(':h: your antenatal clinic visit is tomorrow. Please bring your mother & child health booklet.', ['h' => $name]));
                }
            }

            foreach (Patient::where('date_of_birth', '>=', now()->subYears(2)->toDateString())->whereNotNull('phone')->get() as $child) {
                $given = Immunization::where('patient_id', $child->id)->pluck('given_on', 'vaccine')->all();
                $due = collect(KepiSchedule::forChild($child->date_of_birth, $given))->filter(fn ($v) => $v['status'] !== 'given' && $v['due']->toDateString() === $tomorrow);
                if ($due->isNotEmpty()) {
                    $send($child, 'immunisation_due', __(':h: :child is due for :v tomorrow. Please bring the child health booklet.', ['h' => $name, 'child' => $child->first_name, 'v' => $due->pluck('label')->implode(', ')]));
                }
            }

            $this->line("{$tenant->name}: {$sent} reminder(s)");
        }

        $context->clear();

        return self::SUCCESS;
    }
}
