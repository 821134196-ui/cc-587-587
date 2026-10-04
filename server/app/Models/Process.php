<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Process extends Model
{
    protected $fillable = ['code', 'name', 'sequence'];

    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    public static function firstStation(): ?self
    {
        return static::orderBy('sequence')->first();
    }

    public static function lastStation(): ?self
    {
        return static::orderByDesc('sequence')->first();
    }

    public function nextStation(): ?self
    {
        return static::where('sequence', '>', $this->sequence)->orderBy('sequence')->first();
    }

    public function isLast(): bool
    {
        return $this->nextStation() === null;
    }
}
