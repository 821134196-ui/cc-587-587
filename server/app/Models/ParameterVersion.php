<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParameterVersion extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = ['equipment_id', 'version', 'params', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['params' => 'array'];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
