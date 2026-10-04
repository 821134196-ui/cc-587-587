<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchTransfer extends Model
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'production_order_id', 'batch_id',
        'from_process_id', 'from_station_id', 'to_process_id', 'to_station_id',
        'param_version_id', 'quantity', 'status',
        'released_by', 'accepted_by', 'released_at', 'accepted_at', 'note',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'released_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function batch(): BelongsTo { return $this->belongsTo(Batch::class); }
    public function fromProcess(): BelongsTo { return $this->belongsTo(Process::class, 'from_process_id'); }
    public function toProcess(): BelongsTo { return $this->belongsTo(Process::class, 'to_process_id'); }
    public function fromStation(): BelongsTo { return $this->belongsTo(Station::class, 'from_station_id'); }
    public function toStation(): BelongsTo { return $this->belongsTo(Station::class, 'to_station_id'); }
    public function paramVersion(): BelongsTo { return $this->belongsTo(ParameterVersion::class); }
    public function releaser(): BelongsTo { return $this->belongsTo(User::class, 'released_by'); }
    public function accepter(): BelongsTo { return $this->belongsTo(User::class, 'accepted_by'); }
}
