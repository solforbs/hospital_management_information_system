<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabGateway;
use App\Models\LabInstrument;
use App\Services\Diagnostics\InstrumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LIS API for device gateways (see docs/lab-analyser-integration.md).
 *
 *   POST /api/lis/v1/heartbeat           instruments + code mappings for the gateway config
 *   GET  /api/lis/v1/orders?instrument=  pending samples for a bidirectional analyser
 *   GET  /api/lis/v1/orders/{sample}     host query: what to run on this sample
 *   POST /api/lis/v1/results             results for one sample
 */
class LisController extends Controller
{
    public function __construct(protected InstrumentService $instruments) {}

    public function heartbeat(Request $request): JsonResponse
    {
        $gateway = $this->gateway($request);
        $request->validate(['version' => ['nullable', 'string', 'max:30']]);

        if ($request->filled('version')) {
            $gateway->forceFill(['version' => $request->string('version')->value(), 'last_seen_at' => now()])->save();
        }

        return response()->json([
            'gateway' => ['name' => $gateway->name, 'hospital' => $gateway->tenant->name],
            'server_time' => now()->toIso8601String(),
            'instruments' => $gateway->instruments()->where('active', true)->with('mappings.test')->get()->map(fn (LabInstrument $i) => [
                'code' => $i->code,
                'name' => $i->name,
                'protocol' => $i->protocol,
                'bidirectional' => $i->bidirectional,
                'mappings' => $i->mappings->map(fn ($m) => ['code' => $m->instrument_code, 'test' => $m->test?->code, 'component' => $m->component])->values(),
            ])->values(),
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $instrument = $this->instrument($request, $request->query('instrument'));
        $orders = $instrument->bidirectional ? $this->instruments->pendingOrders($instrument) : [];
        $this->instruments->logOrdersSent($this->gateway($request), $instrument, $orders);

        return response()->json(['instrument' => $instrument->code, 'orders' => $orders]);
    }

    public function order(Request $request, string $sample): JsonResponse
    {
        $instrument = $this->instrument($request, $request->query('instrument'));
        $orders = $this->instruments->pendingOrders($instrument, $sample);
        $this->instruments->logOrdersSent($this->gateway($request), $instrument, $orders, $sample);

        return response()->json(['instrument' => $instrument->code, 'sample_id' => $sample, 'orders' => $orders]);
    }

    public function results(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instrument' => ['required', 'string', 'max:40'],
            'sample_id' => ['required', 'string', 'max:60'],
            'results' => ['required', 'array', 'min:1', 'max:200'],
            'results.*.code' => ['required', 'string', 'max:40'],
            'results.*.value' => ['present', 'nullable', 'string', 'max:60'],
            'results.*.unit' => ['nullable', 'string', 'max:30'],
            'results.*.flag' => ['nullable', 'string', 'max:10'],
            'results.*.reference_range' => ['nullable', 'string', 'max:60'],
            'raw' => ['nullable', 'string', 'max:65000'],
        ]);

        $instrument = $this->instrument($request, $data['instrument']);
        $outcome = $this->instruments->receiveResults($this->gateway($request), $instrument, trim($data['sample_id']), array_map(fn ($r) => $r + ['value' => ''], $data['results']), $data['raw'] ?? null);

        // 200 for accepted/partial; 202 tells the gateway "received, needs human attention" so it does not retry.
        return response()->json($outcome, in_array($outcome['status'], ['accepted', 'partial'], true) ? 200 : 202);
    }

    protected function gateway(Request $request): LabGateway
    {
        return $request->attributes->get('lab_gateway');
    }

    protected function instrument(Request $request, ?string $code): LabInstrument
    {
        $instrument = $this->gateway($request)->instruments()->where('code', (string) $code)->where('active', true)->first();
        abort_unless($instrument, 404, 'Unknown or inactive instrument for this gateway.');

        return $instrument;
    }
}
