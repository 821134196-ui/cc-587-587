<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReworkOrder extends Model
{
    public const OPEN = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const DONE = 'done';

    protected $fillable = [
        'production_order_id', 'qc_inspection_id', 'production_record_id',
        'source_batch_id', 'rework_batch_id',
        'from_process_id', 'to_process_id', 'quantity', 'status', 'created_by',
    ];

    protected $casts = ['quantity' => 'integer'];

    public function sourceBatch(): BelongsTo { return $this->belongsTo(Batch::class, 'source_batch_id'); }
    public function reworkBatch(): BelongsTo { return $this->belongsTo(Batch::class, 'rework_batch_id'); }
    public function fromProcess(): BelongsTo { return $this->belongsTo(Process::class, 'from_process_id'); }
    public function toProcess(): BelongsTo { return $this->belongsTo(Process::class, 'to_process_id'); }
}
