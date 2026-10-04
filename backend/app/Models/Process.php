<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Process extends Model
{
    protected $fillable = [
        'product_code', 'sequence', 'code', 'name',
        'station_id', 'is_final', 'is_active',
    ];

    protected $casts = ['is_final' => 'boolean', 'is_active' => 'boolean'];

    public function station(): BelongsTo { return $this->belongsTo(Station::class); }
    public function templates(): HasMany { return $this->hasMany(ParameterTemplate::class); }
    public function versions(): HasMany { return $this->hasMany(ParameterVersion::class); }
}
