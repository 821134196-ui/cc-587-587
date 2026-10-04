<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParameterVersion extends Model
{
    protected $fillable = [
        'process_id', 'version', 'status', 'change_note', 'created_by', 'activated_at',
    ];

    protected $casts = ['activated_at' => 'datetime'];

    public function process(): BelongsTo { return $this->belongsTo(Process::class); }

    public function items(): HasMany
    {
        return $this->hasMany(ParameterVersionItem::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** 版本激活后不可变，只允许修改 draft */
    public function isLocked(): bool
    {
        return in_array($this->status, ['active', 'retired'], true);
    }
}
