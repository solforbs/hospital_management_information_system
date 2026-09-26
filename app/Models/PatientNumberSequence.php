<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientNumberSequence extends Model
{
    protected $fillable = ['tenant_id', 'year', 'last_number'];
}
