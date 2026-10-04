<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionRecord extends Model
{
    protected $fillable = [
        'batch_id', 'process_id', 'station_id', 'equipment_id', 'operator_id',
        'param_version_id', 'shift',
        'input_qty', 'good_qty', 'rework_qty', 'scrap_qty',
        'actual_params', 'equipment_reading_id',
        'check_status', 'check_detail', 'note',
    ];

    protected $casts = [
        'input_qty' => 'integer',
        'good_qty' => 'integer',
        'rework_qty' => 'integer',
        'scrap_qty' => 'integer',
        'actual_params' => 'array',
    ];

    public function batch(): BelongsTo { return $this->belongsTo(Batch::class); }
    public function process(): BelongsTo { return $this->belongsTo(Process::class); }
    public function station(): BelongsTo { return $this->belongsTo(Station::class); }
    public function equipment(): BelongsTo { return $this->belongsTo(Equipment::class); }
    public function operator(): BelongsTo { return $this->belongsTo(User::class, 'operator_id'); }
    public function paramVersion(): BelongsTo { return $this->belongsTo(ParameterVersion::class); }
    public function reading(): BelongsTo { return $this->belongsTo(EquipmentReading::class, 'equipment_reading_id'); }
    public function inspections(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(QcInspection::class);
    }

    public function accountedQty(): int
    {
        return $this->good_qty + $this->rework_qty + $this->scrap_qty;
    }
}
