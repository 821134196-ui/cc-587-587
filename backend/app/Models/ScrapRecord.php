<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapRecord extends Model
{
    protected $fillable = [
        'production_order_id', 'batch_id', 'production_record_id',
        'quantity', 'reason', 'created_by',
    ];

    protected $casts = ['quantity' => 'integer'];

    public function batch(): BelongsTo { return $this->belongsTo(Batch::class); }
    public function record(): BelongsTo { return $this->belongsTo(ProductionRecord::class); }
}
