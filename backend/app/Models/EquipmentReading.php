<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentReading extends Model
{
    protected $fillable = ['equipment_id', 'batch_id', 'payload', 'simulated', 'captured_at'];

    protected $casts = [
        'payload' => 'array',
        'simulated' => 'boolean',
        'captured_at' => 'datetime',
    ];

    public function equipment(): BelongsTo { return $this->belongsTo(Equipment::class); }
    public function batch(): BelongsTo { return $this->belongsTo(Batch::class); }
}
