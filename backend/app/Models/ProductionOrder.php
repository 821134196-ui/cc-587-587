<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionOrder extends Model
{
    protected $fillable = ['order_no', 'product_code', 'product_name', 'planned_qty', 'status', 'note', 'created_by'];

    public function batches(): HasMany { return $this->hasMany(Batch::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    /** 该单所有批次数量汇总（数量守恒核对用） */
    public function quantitySummary(): array
    {
        $batches = $this->batches()->get(['qty', 'status']);
        return [
            'planned_qty'   => (int) $this->planned_qty,
            'wip_qty'       => (int) $batches
                ->whereNotIn('status', ['split', 'scrapped', 'completed'])->sum('qty'),
            'completed_qty' => (int) $batches->where('status', 'completed')->sum('qty'),
            'scrapped_qty'  => (int) $batches->where('status', 'scrapped')->sum('qty'),
        ];
    }
}
