<?php

namespace App\Http\Controllers;

use App\Models\Encounter;
use App\Services\Audit\AuditLogger;
use App\Services\Hie\FhirMapper;
use Illuminate\Http\JsonResponse;

/**
 * FHIR R4 Bundle for a visit (download / interoperability testing).
 * Tenant-scoped binding: another hospital's visit id returns 404.
 */
class FhirExportController extends Controller
{
    public function __invoke(Encounter $encounter, FhirMapper $mapper, AuditLogger $audit): JsonResponse
    {
        abort_unless(request()->user()->can('encounters.view'), 403);

        $audit->log('fhir.encounter_exported', $encounter);

        return response()->json($mapper->encounterBundle($encounter), 200, [
            'Content-Type' => 'application/fhir+json',
            'Content-Disposition' => 'attachment; filename="'.$encounter->encounter_number.'.fhir.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
