<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SmsMessage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'patient_id', 'to', 'message', 'purpose', 'status', 'provider_message_id', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
