<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BloodUnit extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'unit_number', 'abo', 'rh', 'component', 'volume_ml', 'collected_on', 'expires_on', 'source', 'donor_name', 'status', 'reserved_for_request_id', 'issued_at', 'discard_reason', 'received_by'];

    protected function casts(): array
    {
        return ['collected_on' => 'date', 'expires_on' => 'date', 'issued_at' => 'datetime'];
    }

    public const COMPONENTS = ['whole_blood' => 'Whole blood', 'prbc' => 'Packed red cells', 'ffp' => 'Fresh frozen plasma', 'platelets' => 'Platelets', 'cryo' => 'Cryoprecipitate'];

    public function group(): string
    {
        return $this->abo.($this->rh === 'pos' ? '+' : '−');
    }
}
