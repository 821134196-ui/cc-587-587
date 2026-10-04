<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParameterTemplate extends Model
{
    protected $fillable = ['process_id', 'code', 'name', 'unit', 'min_value', 'max_value'];

    protected $casts = ['min_value' => 'decimal:3', 'max_value' => 'decimal:3'];

    public function process(): BelongsTo { return $this->belongsTo(Process::class); }
}
