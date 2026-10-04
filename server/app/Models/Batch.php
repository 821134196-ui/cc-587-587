<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    public const STATUS_IN_PRODUCTION = 'in_production'; // 在制
    public const STATUS_PENDING_QC = 'pending_qc';       // 待质检
    public const STATUS_RELEASED = 'released';           // 已放行,待下工序接收
    public const STATUS_ON_HOLD = 'on_hold';             // 待处理(数量不符/参数超限/质检驳回)
    public const STATUS_SPLIT = 'split';                 // 已拆分(数量转入子批,本批终止)
    public const STATUS_COMPLETED = 'completed';         // 已完成(末工序放行)

    public const ORIGIN_ROOT = 'root';
    public const ORIGIN_SPLIT = 'split';
    public const ORIGIN_REWORK = 'rework';

    /** 仍占用工位、参与在手数量统计的状态 */
    public const ACTIVE_STATUSES = [
        self::STATUS_IN_PRODUCTION,
        self::STATUS_PENDING_QC,
        self::STATUS_RELEASED,
        self::STATUS_ON_HOLD,
    ];

    protected $fillable = [
        'code', 'production_order_id', 'parent_batch_id', 'origin',
        'current_process_id', 'current_equipment_id', 'parameter_version_id',
        'qty_initial', 'qty_received', 'qty_qualified', 'qty_rework', 'qty_scrap',
        'status', 'hold_reason', 'registered_by', 'qc_by', 'qc_at',
    ];

    protected function casts(): array
    {
        return [
            'qc_at' => 'datetime',
            'qty_initial' => 'integer',
            'qty_received' => 'integer',
            'qty_qualified' => 'integer',
            'qty_rework' => 'integer',
            'qty_scrap' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'parent_batch_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Batch::class, 'parent_batch_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class, 'current_process_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'current_equipment_id');
    }

    public function parameterVersion(): BelongsTo
    {
        return $this->belongsTo(ParameterVersion::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BatchEvent::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(DeviceReading::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function qcInspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qc_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
