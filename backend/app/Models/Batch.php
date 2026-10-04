<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    // 状态
    public const QUEUED = 'queued';           // 待下一工位接收
    public const IN_PROGRESS = 'in_progress'; // 加工中
    public const QC_PENDING = 'qc_pending';   // 待质检
    public const ON_HOLD = 'on_hold';         // 待处理（数量不符/参数超限/拒收）
    public const RELEASED = 'released';       // 已放行，在途待目标工位接收
    public const COMPLETED = 'completed';     // 完工
    public const SPLIT = 'split';             // 父批次拆分后
    public const SCRAPPED = 'scrapped';       // 报废
    public const REWORK_DONE = 'rework_done'; // 返工批次回流放行后（语义同完工路径的一部分）

    // 挂起原因
    public const HOLD_QTY = 'qty_mismatch';
    public const HOLD_PARAMS = 'params_out_of_range';
    public const HOLD_REJECTED = 'qc_rejected';

    protected $fillable = [
        'batch_no', 'production_order_id', 'root_batch_id', 'parent_batch_id',
        'origin', 'depth', 'product_code',
        'process_id', 'station_id', 'equipment_id', 'param_version_id',
        'qty', 'status', 'on_hold_reason', 'current_record_id', 'created_by',
    ];

    protected $casts = ['qty' => 'integer', 'depth' => 'integer'];

    public function order(): BelongsTo { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }
    public function rootBatch(): BelongsTo { return $this->belongsTo(Batch::class, 'root_batch_id'); }
    public function parentBatch(): BelongsTo { return $this->belongsTo(Batch::class, 'parent_batch_id'); }
    public function children(): HasMany { return $this->hasMany(Batch::class, 'parent_batch_id'); }
    public function process(): BelongsTo { return $this->belongsTo(Process::class); }
    public function station(): BelongsTo { return $this->belongsTo(Station::class); }
    public function equipment(): BelongsTo { return $this->belongsTo(Equipment::class); }
    public function paramVersion(): BelongsTo { return $this->belongsTo(ParameterVersion::class); }
    public function records(): HasMany { return $this->hasMany(ProductionRecord::class); }
    public function currentRecord(): BelongsTo { return $this->belongsTo(ProductionRecord::class, 'current_record_id'); }
    public function inspections(): HasMany { return $this->hasMany(QcInspection::class); }
    public function transfers(): HasMany { return $this->hasMany(BatchTransfer::class); }
    public function traceEvents(): HasMany { return $this->hasMany(TraceEvent::class); }

    /** 待接收的在途转序单（一个批次最多一条 pending） */
    public function pendingTransfer(): ?BatchTransfer
    {
        return $this->transfers()->where('status', 'pending')->latest('id')->first();
    }

    public function isOccupiedByOtherStation(?int $stationId): bool
    {
        // 在途批次（已放行待接收）只能由目标工位接收，其他工位不能占用
        if ($this->status === self::RELEASED) {
            $t = $this->pendingTransfer();
            return $t !== null && $t->to_station_id !== $stationId;
        }
        // 已在某工位加工/质检/挂起的批次，其他工位不可占用
        if (in_array($this->status, [self::IN_PROGRESS, self::QC_PENDING, self::ON_HOLD], true)
            && $this->station_id !== null && $stationId !== null) {
            return $this->station_id !== $stationId;
        }
        return false;
    }
}
