<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One parameter of a multi-parameter lab result (e.g. HGB in a full blood count). */
class LabResultComponent extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'lab_order_item_id', 'name', 'value', 'unit', 'reference_range', 'flag', 'sort'];
}
