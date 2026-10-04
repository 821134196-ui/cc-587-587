<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Equipment extends Model
{
    protected $table = 'equipments';

    protected $fillable = ['code', 'name', 'process_id'];

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function parameterVersions(): HasMany
    {
        return $this->hasMany(ParameterVersion::class);
    }

    public function activeParameterVersion(): HasOne
    {
        return $this->hasOne(ParameterVersion::class)->where('status', ParameterVersion::STATUS_ACTIVE);
    }
}
