<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    protected $fillable = ['code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function equipment(): HasMany { return $this->hasMany(Equipment::class); }
    public function processes(): HasMany { return $this->hasMany(Process::class); }
}
