<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'kra_pin', 'contact_person', 'phone', 'email', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
