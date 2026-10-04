<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceReading extends Model
{
    protected $fillable = [
        'batch_id', 'equipment_id', 'parameter_version_id',
        'metric', 'value', 'min_value', 'max_value', 'in_limit',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'min_value' => 'float',
            'max_value' => 'float',
            'in_limit' => 'boolean',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function parameterVersion(): BelongsTo
    {
        return $this->belongsTo(ParameterVersion::class);
    }
}
