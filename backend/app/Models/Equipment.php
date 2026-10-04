<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipment extends Model
{
    protected $fillable = ['code', 'name', 'station_id', 'status'];

    public function station(): BelongsTo { return $this->belongsTo(Station::class); }
    public function readings(): HasMany { return $this->hasMany(EquipmentReading::class); }
}
