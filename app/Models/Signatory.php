<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person whose name, title and signature appear on printed documents
 * (receipts, referral letters, discharge summaries, reports...).
 */
class Signatory extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'user_id', 'name', 'title', 'qualifications', 'registration',
        'signature_path', 'documents', 'sort_order', 'active',
    ];

    protected function casts(): array
    {
        return [
            'documents' => 'array',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function signatureUrl(): ?string
    {
        return $this->signature_path ? route('branding.signature', ['signatory' => $this->id, 'v' => substr(md5($this->signature_path), 0, 8)]) : null;
    }
}
