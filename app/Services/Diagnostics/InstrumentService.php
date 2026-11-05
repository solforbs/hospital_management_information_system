<?php

namespace App\Services\Diagnostics;

use App\Models\LabGateway;
use App\Models\LabInstrument;
use App\Models\LabInstrumentMessage;
use App\Models\LabOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Bridges analysers (through a device gateway) and the laboratory workflow.
 *
 * - Orders out: collected samples whose tests this analyser runs, keyed by
 *   specimen number (the barcode on the tube).
 * - Results in: analyser codes are mapped to HMIS tests/components and saved
 *   as "resulted". Verification and release stay with qualified staff.
 *
 * Everything is logged in lab_instrument_messages.
 */
class InstrumentService
{
    public function __construct(protected LaboratoryService $lab) {}

    /** Orders (worklist) for an analyser: one entry per sample. */
    public function pendingOrders(LabInstrument $instrument, ?string $sampleId = null): array
    {
        $codesByTest = $instrument->mappings()->get()->groupBy('lab_test_id')->map(fn ($m) => $m->pluck('instrument_code')->values()->all());

        if ($codesByTest->isEmpty()) {
            return [];
        }

        $items = LabOrderItem::with(['order.patient', 'test'])
            ->where('status', 'collected')
            ->whereIn('lab_test_id', $codesByTest->keys())
            ->whereNotNull('specimen_number')
            ->when($sampleId, fn ($q) => $q->where('specimen_number', $sampleId))
            ->when(! $sampleId, fn ($q) => $q->where('collected_at', '>=', now()->subDays(7)))
            ->orderBy('collected_at')
            ->limit(200)
            ->get();

        return $items->map(function (LabOrderItem $item) use ($codesByTest) {
            $patient = $item->order->patient;

            return [
                'sample_id' => $item->specimen_number,
                'priority' => $item->order->priority, // routine, urgent, stat
                'collected_at' => $item->collected_at?->toIso8601String(),
                'specimen_type' => $item->test->specimen_type,
                'test' => ['code' => $item->test->code, 'name' => $item->test->name],
                'instrument_codes' => $codesByTest[$item->lab_test_id],
                'patient' => [
                    'number' => $patient->mrn,
                    'last_name' => $patient->last_name,
                    'first_name' => $patient->first_name,
                    'sex' => match ($patient->sex) {
                        'male' => 'M', 'female' => 'F', default => 'U'
                    },
                    'date_of_birth' => $patient->date_of_birth?->format('Y-m-d'),
                ],
            ];
        })->values()->all();
    }

    /**
     * Accepts results for one sample from an analyser.
     *
     * @param  list<array{code: string, value: string, unit?: ?string, flag?: ?string, reference_range?: ?string}>  $results
     * @return array{status: string, detail: string, matched: list<string>, unmapped: list<string>}
     */
    public function receiveResults(LabGateway $gateway, LabInstrument $instrument, string $sampleId, array $results, ?string $raw = null): array
    {
        $instrument->forceFill(['last_message_at' => now()])->save();

        $item = LabOrderItem::with('test')->where('specimen_number', $sampleId)->first();

        if (! $item) {
            return $this->log($gateway, $instrument, 'results', $sampleId, 'unmatched', __('No sample with this ID. Check the barcode, or that the sample was marked collected in HMIS.'), $raw, [], array_column($results, 'code'));
        }

        if (! in_array($item->status, ['collected', 'resulted'], true)) {
            return $this->log($gateway, $instrument, 'results', $sampleId, 'rejected', __('The test is already :status; instrument results cannot change it.', ['status' => $item->status]), $raw, [], array_column($results, 'code'));
        }

        $mappings = $instrument->mappings()->where('lab_test_id', $item->lab_test_id)->get()->keyBy(fn ($m) => strtoupper($m->instrument_code));

        $single = null;
        $components = [];
        $matched = [];
        $unmapped = [];

        foreach ($results as $r) {
            $code = strtoupper(trim((string) $r['code']));
            $mapping = $mappings->get($code);
            $value = trim((string) $r['value']);

            if (! $mapping || $value === '') {
                $unmapped[] = $code;

                continue;
            }

            $matched[] = $code;
            $unit = isset($r['unit']) && $r['unit'] !== '' ? (string) $r['unit'] : null;
            $range = isset($r['reference_range']) && $r['reference_range'] !== '' ? (string) $r['reference_range'] : null;
            $flag = self::normaliseFlag($r['flag'] ?? null) ?? self::flagFromRange($value, $range);

            if ($mapping->component) {
                $components[] = ['name' => $mapping->component, 'value' => $value, 'unit' => $unit, 'reference_range' => $range, 'flag' => $flag];
            } else {
                $single = ['value' => $value, 'unit' => $unit, 'reference_range' => $range, 'flag' => $flag];
            }
        }

        if (! $single && ! $components) {
            return $this->log($gateway, $instrument, 'results', $sampleId, 'unmatched', __('None of the analyser codes are mapped to :test on this instrument.', ['test' => $item->test->name]), $raw, [], $unmapped);
        }

        try {
            $this->lab->recordInstrumentResult($item, $instrument, $components ? null : $single, $components);
        } catch (ValidationException $e) {
            return $this->log($gateway, $instrument, 'results', $sampleId, 'rejected', collect($e->errors())->flatten()->implode(' '), $raw, $matched, $unmapped);
        }

        return $this->log(
            $gateway, $instrument, 'results', $sampleId,
            $unmapped ? 'partial' : 'accepted',
            $unmapped ? __('Saved for :test. Unmapped codes ignored: :codes', ['test' => $item->test->name, 'codes' => implode(', ', $unmapped)]) : __('Saved for :test; awaiting verification.', ['test' => $item->test->name]),
            $raw, $matched, $unmapped,
        );
    }

    public function logOrdersSent(LabGateway $gateway, LabInstrument $instrument, array $orders, ?string $sampleId = null): void
    {
        if ($orders || $sampleId) {
            LabInstrumentMessage::create([
                'tenant_id' => $instrument->tenant_id, 'lab_gateway_id' => $gateway->id, 'lab_instrument_id' => $instrument->id,
                'direction' => 'out', 'type' => $sampleId ? 'query' : 'orders', 'sample_id' => $sampleId,
                'status' => 'sent', 'detail' => trans_choice(':n sample|:n samples', count($orders), ['n' => count($orders)]),
            ]);
        }
    }

    /** H/L/HH/LL/N/A style flags from analysers -> HMIS flags. */
    public static function normaliseFlag(?string $flag): ?string
    {
        return match (strtoupper(trim((string) $flag))) {
            'HH', 'LL', 'AA', 'C', 'CRIT', 'CRITICAL', 'PANIC', '>', '<' => 'critical',
            'H', 'HI', 'HIGH', '+' => 'high',
            'L', 'LO', 'LOW', '-' => 'low',
            'N', 'NORMAL' => 'normal',
            default => null,
        };
    }

    /** Flag from a "low-high" reference range when the analyser sent none. */
    public static function flagFromRange(string $value, ?string $range): ?string
    {
        if (! is_numeric($value) || ! $range || ! preg_match('/^\s*(-?[\d.]+)\s*(?:-|–|to)\s*(-?[\d.]+)\s*$/', $range, $m)) {
            return null;
        }

        $v = (float) $value;

        return match (true) {
            $v < (float) $m[1] => 'low',
            $v > (float) $m[2] => 'high',
            default => 'normal',
        };
    }

    protected function log(LabGateway $gateway, LabInstrument $instrument, string $type, ?string $sampleId, string $status, string $detail, ?string $raw, array $matched, array $unmapped): array
    {
        LabInstrumentMessage::create([
            'tenant_id' => $instrument->tenant_id, 'lab_gateway_id' => $gateway->id, 'lab_instrument_id' => $instrument->id,
            'direction' => 'in', 'type' => $type, 'sample_id' => $sampleId, 'status' => $status, 'detail' => $detail,
            'payload' => $raw !== null ? mb_substr($raw, 0, 65000) : null,
        ]);

        return ['status' => $status, 'detail' => $detail, 'matched' => $matched, 'unmapped' => $unmapped];
    }

    /** Recent messages that need attention. */
    public function problems(int $limit = 20): Collection
    {
        return LabInstrumentMessage::with('instrument')->whereIn('status', ['unmatched', 'rejected', 'partial'])->latest()->limit($limit)->get();
    }
}
