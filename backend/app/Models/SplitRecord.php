<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SplitRecord extends Model
{
    protected $fillable = [
        'production_order_id', 'parent_batch_id', 'child_batch_id', 'quantity', 'created_by',
    ];

    protected $casts = ['quantity' => 'integer'];

    public function parentBatch(): BelongsTo { return $this->belongsTo(Batch::class, 'parent_batch_id'); }
    public function childBatch(): BelongsTo { return $this->belongsTo(Batch::class, 'child_batch_id'); }
}
