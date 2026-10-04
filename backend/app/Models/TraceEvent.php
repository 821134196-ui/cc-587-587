<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TraceEvent extends Model
{
    protected $fillable = [
        'production_order_id', 'batch_id', 'event_type',
        'from_node', 'to_node', 'quantity', 'payload', 'actor_id', 'occurred_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function batch(): BelongsTo { return $this->belongsTo(Batch::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
