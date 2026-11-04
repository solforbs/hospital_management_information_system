<?php

namespace App\Services\Billing;

use App\Models\InsuranceClaim;
use App\Models\Remittance;
use App\Models\RemittanceLine;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment advice (remittance) from SHA or insurers, matched automatically
 * to claims by claim number, SHA invoice number, provider claim number or
 * claim GUID. Each matched line posts the payment to the invoice through
 * InsuranceService. A remittance reference is processed only once.
 */
class RemittanceService
{
    public function __construct(protected InsuranceService $insurance, protected AuditLogger $audit) {}

    /**
     * @param  array<int, array{claim_reference: string, amount: float|string}>  $lines
     */
    public function ingest(string $payer, string $reference, ?string $paidOn, array $lines, string $source): Remittance
    {
        $reference = trim($reference);

        if ($reference === '' || ! $lines) {
            throw ValidationException::withMessages(['remittance' => __('A remittance needs a reference and at least one line.')]);
        }

        if (Remittance::where('payer', $payer)->where('reference', $reference)->exists()) {
            throw ValidationException::withMessages(['remittance' => __('Remittance :ref from :payer was already processed.', ['ref' => $reference, 'payer' => $payer])]);
        }

        return DB::transaction(function () use ($payer, $reference, $paidOn, $lines, $source) {
            $remittance = Remittance::create([
                'payer' => $payer,
                'reference' => $reference,
                'paid_on' => $paidOn,
                'total_amount' => collect($lines)->sum(fn ($l) => (float) $l['amount']),
                'source' => $source,
                'created_by' => Auth::id(),
            ]);

            $matched = $unmatched = 0;
            $seen = [];

            foreach ($lines as $line) {
                $ref = trim((string) $line['claim_reference']);
                $amount = round((float) $line['amount'], 2);
                $claim = $this->findClaim($ref);
                [$status, $note] = ['unmatched', __('No claim with this reference')];

                if ($claim && isset($seen[$claim->id])) {
                    [$status, $note] = ['duplicate', __('Claim appears twice in this remittance')];
                } elseif ($claim && $claim->status === 'paid') {
                    [$status, $note] = ['duplicate', __('Claim already paid')];
                } elseif ($claim && $amount > 0) {
                    $seen[$claim->id] = true;
                    $balance = (float) $claim->invoice->fresh()->balance;

                    if (in_array($claim->status, ['draft', 'rejected'], true)) {
                        $claim->update(['status' => 'submitted', 'submitted_at' => $claim->submitted_at ?? now()]);
                    }

                    $this->insurance->recordResponse($claim->fresh(), 'paid', $amount, null);
                    [$status, $note] = $amount > $balance + 0.005
                        ? ['exceeds_balance', __('Paid :a but invoice balance was :b; excess not posted', ['a' => number_format($amount, 2), 'b' => number_format($balance, 2)])]
                        : ['matched', null];
                }

                RemittanceLine::create([
                    'remittance_id' => $remittance->id,
                    'insurance_claim_id' => $claim?->id,
                    'claim_reference' => $ref,
                    'amount' => $amount,
                    'status' => $status,
                    'note' => $note,
                ]);

                in_array($status, ['matched', 'exceeds_balance'], true) ? $matched++ : $unmatched++;
            }

            $remittance->update(['matched_count' => $matched, 'unmatched_count' => $unmatched]);
            $this->audit->log('remittance.processed', $remittance, ['matched' => $matched, 'unmatched' => $unmatched, 'source' => $source]);

            return $remittance;
        });
    }

    /**
     * Parse a CSV payment advice: header row with claim reference + amount
     * columns (common names accepted).
     *
     * @return array<int, array{claim_reference: string, amount: float}>
     */
    public function parseCsv(string $contents): array
    {
        $rows = array_map(fn ($l) => str_getcsv($l), preg_split('/\r\n|\r|\n/', trim($contents)));
        $header = array_map(fn ($h) => strtolower(trim((string) $h, "\xEF\xBB\xBF \"")), array_shift($rows) ?? []);

        $refCol = collect(['claim_reference', 'claim_no', 'claim number', 'invoice_number', 'provider_claim_no', 'reference'])->map(fn ($c) => array_search($c, $header, true))->first(fn ($i) => $i !== false);
        $amtCol = collect(['amount', 'paid_amount', 'amount_paid', 'paid'])->map(fn ($c) => array_search($c, $header, true))->first(fn ($i) => $i !== false);

        if ($refCol === null || $amtCol === null) {
            throw ValidationException::withMessages(['file' => __('The file needs a claim reference column and an amount column.')]);
        }

        return collect($rows)
            ->filter(fn ($r) => trim((string) ($r[$refCol] ?? '')) !== '')
            ->map(fn ($r) => ['claim_reference' => trim((string) $r[$refCol]), 'amount' => (float) str_replace([',', ' '], '', (string) ($r[$amtCol] ?? 0))])
            ->values()->all();
    }

    public function findClaim(string $ref): ?InsuranceClaim
    {
        if ($ref === '') {
            return null;
        }

        return InsuranceClaim::with('invoice')
            ->where(fn ($q) => $q->where('claim_number', $ref)
                ->orWhere('external_reference', $ref)
                ->orWhere('gateway_response->provider_claim_no', $ref)
                ->orWhere('gateway_response->guid', $ref))
            ->first();
    }
}
