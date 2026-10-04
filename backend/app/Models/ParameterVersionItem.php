<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParameterVersionItem extends Model
{
    protected $fillable = [
        'parameter_version_id', 'parameter_template_id',
        'set_value', 'min_value', 'max_value',
    ];

    protected $casts = [
        'set_value' => 'decimal:3',
        'min_value' => 'decimal:3',
        'max_value' => 'decimal:3',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ParameterVersion::class, 'parameter_version_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ParameterTemplate::class, 'parameter_template_id');
    }
}
