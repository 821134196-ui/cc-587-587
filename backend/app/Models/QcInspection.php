<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QcInspection extends Model
{
    public const PASSED = 'passed';
    public const REJECTED = 'rejected';
    public const SCRAPPED = 'scrapped';

    protected $fillable = [
        'production_record_id', 'batch_id', 'inspector_id', 'decision',
        'rework_to_process_id', 'passed_qty', 'rework_qty', 'scrap_qty', 'note',
    ];

    protected $casts = [
        'passed_qty' => 'integer',
        'rework_qty' => 'integer',
        'scrap_qty' => 'integer',
    ];

    public function record(): BelongsTo { return $this->belongsTo(ProductionRecord::class, 'production_record_id'); }
    public function batch(): BelongsTo { return $this->belongsTo(Batch::class); }
    public function inspector(): BelongsTo { return $this->belongsTo(User::class, 'inspector_id'); }
    public function reworkToProcess(): BelongsTo { return $this->belongsTo(Process::class, 'rework_to_process_id'); }
}
