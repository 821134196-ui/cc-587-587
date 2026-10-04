<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchEvent extends Model
{
    public const TYPE_CREATE = 'create';
    public const TYPE_OUTPUT = 'output';
    public const TYPE_HOLD = 'hold';
    public const TYPE_QC_RELEASE = 'qc_release';
    public const TYPE_QC_REJECT = 'qc_reject';
    public const TYPE_RECEIVE = 'receive';
    public const TYPE_SPLIT = 'split';
    public const TYPE_REWORK = 'rework';
    public const TYPE_COMPLETE = 'complete';

    protected $fillable = [
        'batch_id', 'production_order_id', 'type',
        'from_process_id', 'to_process_id', 'from_equipment_id', 'to_equipment_id',
        'parameter_version_id', 'qty', 'qty_qualified', 'qty_rework', 'qty_scrap',
        'actor_id', 'note', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function fromProcess(): BelongsTo
    {
        return $this->belongsTo(Process::class, 'from_process_id');
    }

    public function toProcess(): BelongsTo
    {
        return $this->belongsTo(Process::class, 'to_process_id');
    }

    public function fromEquipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'from_equipment_id');
    }

    public function toEquipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'to_equipment_id');
    }

    public function parameterVersion(): BelongsTo
    {
        return $this->belongsTo(ParameterVersion::class);
    }
}
