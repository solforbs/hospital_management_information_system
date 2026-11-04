<?php

namespace App\Services\Hie;

use App\Models\Encounter;
use App\Models\Facility;
use App\Services\Audit\AuditLogger;

/**
 * Sends a completed visit to the national Shared Health Record as a FHIR
 * R4 transaction Bundle through the DHA HIE. Re-sending is safe: resource
 * ids are deterministic, so the SHR updates rather than duplicates.
 */
class SharedHealthRecordService
{
    public function __construct(protected HieClient $client, protected FhirMapper $mapper, protected AuditLogger $audit) {}

    public function push(Encounter $encounter): bool
    {
        $bundle = $this->mapper->encounterBundle($encounter);

        try {
            $this->client->post(config('hms.hie.shr_path'), $bundle, $encounter->facility_id ? Facility::find($encounter->facility_id) : null);
        } catch (HieException $e) {
            $encounter->forceFill(['shr_status' => 'failed', 'shr_error' => mb_substr($e->getMessage(), 0, 250)])->save();

            return false;
        }

        $encounter->forceFill(['shr_status' => 'sent', 'shr_sent_at' => now(), 'shr_error' => null])->save();
        $this->audit->log('shr.encounter_sent', $encounter, ['resources' => count($bundle['entry'])]);

        return true;
    }
}
