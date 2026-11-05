<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\DeathNotification;
use App\Models\LabOrder;
use App\Models\MedicalCertificate;
use App\Models\Newborn;
use App\Models\PrescriptionItem;
use App\Models\RadiologyOrder;
use App\Models\Surgery;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;

/**
 * Printable clinical documents on the institution letterhead with the
 * signatories assigned to each document type. All bindings are
 * tenant-scoped; printing is audited.
 */
class PrintController extends Controller
{
    public function __construct(protected TenantContext $context, protected AuditLogger $audit) {}

    protected function render(string $view, string $document, $subject, array $data): View
    {
        $this->audit->log('document.printed', $subject, ['document' => $document]);

        return view($view, $data + [
            'tenant' => $this->context->tenant(),
            'facility' => $this->context->facility(),
        ]);
    }

    public function discharge(Admission $admission): View
    {
        abort_unless(request()->user()->canAny(['wards.view', 'encounters.view']), 403);
        abort_unless($admission->status === 'discharged', 404);

        $admission->load(['patient', 'ward', 'doctor', 'encounter.diagnoses']);

        return $this->render('print.discharge', 'discharge', $admission, [
            'admission' => $admission,
            'surgeries' => Surgery::where('admission_id', $admission->id)->where('status', 'completed')->get(),
            'medications' => PrescriptionItem::with('medicine')->whereHas('prescription', fn ($q) => $q->where('encounter_id', $admission->encounter_id))->where('status', '!=', 'cancelled')->get(),
        ]);
    }

    public function lab(LabOrder $labOrder): View
    {
        abort_unless(request()->user()->canAny(['lab.orders.view', 'encounters.view']), 403);

        $labOrder->load(['patient', 'orderedBy', 'items.test', 'items.verifiedBy', 'items.resultedBy']);
        $released = $labOrder->items->whereNotNull('released_at');
        abort_if($released->isEmpty(), 404, __('No released results on this order yet.'));

        return $this->render('print.lab', 'lab_report', $labOrder, ['order' => $labOrder, 'items' => $released]);
    }

    public function radiology(RadiologyOrder $radiologyOrder): View
    {
        abort_unless(request()->user()->canAny(['radiology.orders.view', 'encounters.view']), 403);
        abort_unless($radiologyOrder->status === 'verified', 404, __('The report has not been verified yet.'));

        $radiologyOrder->load(['patient', 'exam', 'orderedBy', 'reportedBy']);

        return $this->render('print.radiology', 'radiology_report', $radiologyOrder, ['order' => $radiologyOrder]);
    }

    public function certificate(MedicalCertificate $certificate): View
    {
        abort_unless(request()->user()->canAny(['encounters.view', 'clinical_notes.create']), 403);
        $certificate->load(['patient', 'issuer']);

        return $this->render('print.certificate', 'medical_certificate', $certificate, ['certificate' => $certificate]);
    }

    public function birth(Newborn $newborn): View
    {
        abort_unless(request()->user()->canAny(['maternity.view', 'maternity.manage', 'maternity.deliver']), 403);
        $newborn->load(['labour.pregnancy', 'mother', 'baby', 'labour.deliveredBy']);
        abort_unless($newborn->outcome === 'live', 404);

        return $this->render('print.birth', 'birth_notification', $newborn, ['newborn' => $newborn]);
    }

    public function death(DeathNotification $death): View
    {
        abort_unless(request()->user()->canAny(['mortuary.manage', 'encounters.view', 'wards.discharge']), 403);
        $death->load(['patient', 'certifier']);

        return $this->render('print.death', 'death_notification', $death, ['death' => $death]);
    }
}
