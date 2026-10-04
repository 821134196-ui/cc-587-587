<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionOrder extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = ['code', 'product_name', 'planned_qty', 'status'];

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BatchEvent::class);
    }
}
