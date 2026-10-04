<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessException;
use App\Models\Batch;
use App\Models\ParameterVersion;
use App\Models\Process;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\ProductionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyWorkflow extends Command
{
    protected $signature = 'verify:workflow';
    protected $description = '端到端检验：数量核对、参数换版、返工回流、权限/占用规则与追溯链完整性';

    private int $pass = 0;
    private int $fail = 0;

    public function handle(ProductionService $svc): int
    {
        $this->info('====== 工厂半成品转序系统 · 业务规则端到端检验 ======');

        $cut = User::where('email', 'cut@factory.local')->first();
        $cnc = User::where('email', 'cnc@factory.local')->first();
        $weld = User::where('email', 'weld@factory.local')->first();
        $qc = User::where('email', 'qc@factory.local')->first();
        $eng = User::where('email', 'eng@factory.local')->first();

        $product = 'P-AX100';
        $p10 = Process::where('product_code', $product)->where('code', 'OP-10')->first();
        $p20 = Process::where('product_code', $product)->where('code', 'OP-20')->first();
        $p30 = Process::where('product_code', $product)->where('code', 'OP-30')->first();

        /* ---------- 场景 1：正常转序全流程（100 件一路完工） ---------- */
        $this->section('场景1 正常流程：下料→机加→焊接 全工序转序完工');
        $o1 = $svc->createOrder($eng, [
            'order_no' => 'MO-VERIFY-01', 'product_code' => $product,
            'product_name' => '验证单1', 'planned_qty' => 100,
        ]);
        $svc->releaseOrder($o1, $eng);
        $b = Batch::where('production_order_id', $o1->id)->first();

        $svc->receive($b, $cut);
        $b = $b->fresh();
        $this->check('下料接收后锁定参数版本 v1', $b->paramVersion?->version === 'v1');
        $svc->registerProduction($b, $cut, $this->reg($b, 100, 0, 0));
        $b = $b->fresh();
        $this->check('登记后进入待质检', $b->status === Batch::QC_PENDING);
        $rec1 = $b->currentRecord;
        $svc->inspect($rec1, $qc, ['passed_qty' => 100, 'rework_qty' => 0, 'scrap_qty' => 0]);
        $b = $b->fresh();
        $this->check('质检放行后在途，目标机加工位', $b->status === Batch::RELEASED && $b->station_id === $p20->station_id);

        $svc->receive($b, $cnc);
        $svc->registerProduction($b->fresh(), $cnc, $this->reg($b->fresh(), 100, 0, 0));
        $b = $b->fresh();
        $svc->inspect($b->currentRecord, $qc, ['passed_qty' => 100, 'rework_qty' => 0, 'scrap_qty' => 0]);
        $svc->receive($b->fresh(), $weld);
        $svc->registerProduction($b->fresh(), $weld, $this->reg($b->fresh(), 100, 0, 0));
        $b = $b->fresh();
        $svc->inspect($b->currentRecord, $qc, ['passed_qty' => 100, 'rework_qty' => 0, 'scrap_qty' => 0]);
        $b = $b->fresh();
        $this->check('末道工序放行后完工', $b->status === Batch::COMPLETED && $b->qty === 100);
        $this->reconcile($o1);

        /* ---------- 场景 2：操作员不能批准自己生产的批次 ---------- */
        $this->section('场景2 权责分离：操作员不能质检自己登记的批次');
        $o2 = $svc->createOrder($eng, ['order_no' => 'MO-VERIFY-02', 'product_code' => $product,
            'product_name' => '验证单2', 'planned_qty' => 10]);
        $svc->releaseOrder($o2, $eng);
        $b2 = Batch::where('production_order_id', $o2->id)->first();
        $svc->receive($b2, $cut);
        $svc->registerProduction($b2->fresh(), $cut, $this->reg($b2->fresh(), 10, 0, 0));
        $b2 = $b2->fresh();
        $this->expectFail('下料员自批被拒绝（角色分离）', function () use ($svc, $b2, $cut) {
            $svc->inspect($b2->currentRecord, $cut, ['passed_qty' => 10, 'rework_qty' => 0, 'scrap_qty' => 0]);
        });
        // 纵深防御：即便登记人就是质检员本人（数据异常），自批业务规则同样拦截
        $b2->currentRecord->update(['operator_id' => $qc->id]);
        $this->expectFail('登记人=质检人本人时自批被拒绝（业务规则兜底）', function () use ($svc, $b2, $qc) {
            $svc->inspect($b2->currentRecord->fresh(), $qc, ['passed_qty' => 10, 'rework_qty' => 0, 'scrap_qty' => 0]);
        });
        $b2->currentRecord->update(['operator_id' => $cut->id]); // 复原以便后续步骤

        /* ---------- 场景 3：两个工位不能同时占用同一批 ---------- */
        $this->section('场景3 占用互斥：在途批次只能由目标工位接收');
        $svc->inspect($b2->currentRecord, $qc, ['passed_qty' => 10, 'rework_qty' => 0, 'scrap_qty' => 0]);
        $b2 = $b2->fresh();
        $this->expectFail('焊接员抢收应去机加的批次被拒绝', function () use ($svc, $b2, $weld) {
            $svc->receive($b2, $weld);
        });
        $svc->receive($b2, $cnc); // 正确工位接收
        $b2 = $b2->fresh();
        $this->check('目标工位正常接收成功', $b2->status === Batch::IN_PROGRESS);

        /* ---------- 场景 4：数量不符挂起 + 更正 ---------- */
        $this->section('场景4 数量核对：登记数量不符挂起，不得转序，更正后恢复');
        $svc->registerProduction($b2, $cnc, $this->reg($b2->fresh(), 9, 0, 0)); // 合计9≠投入10
        $b2 = $b2->fresh();
        $this->check('数量不符 → 待处理(qty_mismatch)', $b2->status === Batch::ON_HOLD
            && $b2->on_hold_reason === Batch::HOLD_QTY);
        $this->expectFail('挂起批次不能继续质检流转', function () use ($svc, $b2) {
            // 直接尝试放行无 QC_PENDING 记录会失败
            $svc->inspect($b2->currentRecord, User::where('email', 'qc@factory.local')->first(),
                ['passed_qty' => 10, 'rework_qty' => 0, 'scrap_qty' => 0]);
        });
        $svc->resolveHold($b2->currentRecord, $eng, 'correct',
            ['good_qty' => 9, 'rework_qty' => 0, 'scrap_qty' => 1]);
        $b2 = $b2->fresh();
        $this->check('更正（9合格+1报废）后恢复待质检', $b2->status === Batch::QC_PENDING);
        $svc->inspect($b2->currentRecord, $qc, ['passed_qty' => 9, 'rework_qty' => 0, 'scrap_qty' => 1]);
        $b2 = $b2->fresh();
        $this->check('报废子批次已沿原单生成', $b2->qty === 9
            && Batch::where('parent_batch_id', $b2->id)->where('status', Batch::SCRAPPED)->exists());
        $this->reconcile($o2);

        /* ---------- 场景 5：参数超限挂起 + 返工回流 ---------- */
        $this->section('场景5 参数超限：实测超范围挂起，整批返工回流至原工序');
        $o5 = $svc->createOrder($eng, ['order_no' => 'MO-VERIFY-05', 'product_code' => $product,
            'product_name' => '验证单5', 'planned_qty' => 20]);
        $svc->releaseOrder($o5, $eng);
        $b5 = Batch::where('production_order_id', $o5->id)->first();
        $svc->receive($b5, $cut);
        $b5 = $b5->fresh();
        // 下料合格 → 机加，机加读数超限
        $svc->registerProduction($b5, $cut, $this->reg($b5->fresh(), 20, 0, 0));
        $b5 = $b5->fresh();
        $svc->inspect($b5->currentRecord, $qc, ['passed_qty' => 20, 'rework_qty' => 0, 'scrap_qty' => 0]);
        $svc->receive($b5->fresh(), $cnc);
        $b5 = $b5->fresh();
        // 主轴转速超上限 2400：给 2600
        $badParams = $this->paramsAtSet($b5, ['SPINDLE_SPEED' => 2600.0]);
        $svc->registerProduction($b5, $cnc, array_merge($this->reg($b5->fresh(), 20, 0, 0), ['actual_params' => $badParams]));
        $b5 = $b5->fresh();
        $this->check('参数超限 → 待处理(params_out_of_range)', $b5->status === Batch::ON_HOLD
            && $b5->on_hold_reason === Batch::HOLD_PARAMS);
        $this->expectFail('操作员无权解除参数超限挂起', function () use ($svc, $b5, $cnc) {
            $svc->resolveHold($b5->currentRecord, $cnc, 'waive', ['note' => 'x']);
        });
        // 整批返工回流到 OP-10 下料
        $svc->resolveHold($b5->currentRecord, $eng, 'rework', ['rework_to_process_id' => $p10->id]);
        $b5 = $b5->fresh();
        $rwBatch = Batch::where('parent_batch_id', $b5->id)->where('origin', 'rework')->first();
        $this->check('返工回流批次沿原生产单生成、回到 OP-10 排队',
            $rwBatch && $rwBatch->production_order_id === $o5->id
            && $rwBatch->process_id === $p10->id && $rwBatch->qty === 20 && $rwBatch->status === Batch::QUEUED);
        $this->check('回流批次尚未锁定版本（待回流工序接收时锁定）', $rwBatch->param_version_id === null);

        // 回流批次重新走 OP-10 → OP-20 → OP-30
        $svc->receive($rwBatch, $cut);
        $rw = $rwBatch->fresh();
        $svc->registerProduction($rw, $cut, $this->reg($rw->fresh(), 20, 0, 0));
        $rw = $rw->fresh();
        $svc->inspect($rw->currentRecord, $qc, ['passed_qty' => 20, 'rework_qty' => 0, 'scrap_qty' => 0]);
        $svc->receive($rw->fresh(), $cnc);
        $rw = $rw->fresh();
        $svc->registerProduction($rw, $cnc, $this->reg($rw->fresh(), 20, 0, 0));
        $rw = $rw->fresh();
        $svc->inspect($rw->currentRecord, $qc, ['passed_qty' => 20, 'rework_qty' => 0, 'scrap_qty' => 0]);
        $svc->receive($rw->fresh(), $weld);
        $rw = $rw->fresh();
        $svc->registerProduction($rw, $weld, $this->reg($rw->fresh(), 20, 0, 0));
        $rw = $rw->fresh();
        $svc->inspect($rw->currentRecord, $qc, ['passed_qty' => 20, 'rework_qty' => 0, 'scrap_qty' => 0]);
        $rw = $rw->fresh();
        $this->check('返工回流批次重新走完全程并完工，返工单关闭',
            $rw->status === Batch::COMPLETED && $rw->qty === 20);
        $this->check('返工单状态 done',
            \App\Models\ReworkOrder::where('rework_batch_id', $rw->id)->first()->status === 'done');
        $this->reconcile($o5);

        /* ---------- 场景 6：质检返工回流（部分返工） ---------- */
        $this->section('场景6 质检判返工：80放行 + 15返工回流 + 5报废，数量守恒');
        $o6 = $svc->createOrder($eng, ['order_no' => 'MO-VERIFY-06', 'product_code' => $product,
            'product_name' => '验证单6', 'planned_qty' => 100]);
        $svc->releaseOrder($o6, $eng);
        $b6 = Batch::where('production_order_id', $o6->id)->first();
        $svc->receive($b6, $cut);
        $svc->registerProduction($b6->fresh(), $cut, $this->reg($b6->fresh(), 80, 15, 5));
        $b6 = $b6->fresh();
        $this->check('登记 80+15+5=100 守恒，进入待质检', $b6->status === Batch::QC_PENDING);
        $this->expectFail('质检处置合计≠投入时被拒绝', function () use ($svc, $b6, $qc) {
            $svc->inspect($b6->currentRecord, $qc, ['passed_qty' => 80, 'rework_qty' => 10, 'scrap_qty' => 5]);
        });
        $svc->inspect($b6->currentRecord, $qc, [
            'passed_qty' => 80, 'rework_qty' => 15, 'scrap_qty' => 5,
            'rework_to_process_id' => $p10->id, 'note' => '15件尺寸偏差返工',
        ]);
        $b6 = $b6->fresh();
        $rw6 = Batch::where('parent_batch_id', $b6->id)->where('origin', 'rework')->first();
        $x6 = Batch::where('parent_batch_id', $b6->id)->where('status', Batch::SCRAPPED)->first();
        $this->check('父批次80在途去机加 / 返工子批15回OP-10 / 报废子批5',
            $b6->qty === 80 && $b6->status === Batch::RELEASED
            && $rw6->qty === 15 && $rw6->process_id === $p10->id
            && $x6->qty === 5);
        $this->reconcile($o6);

        /* ---------- 场景 7：批次拆分数量守恒 ---------- */
        $this->section('场景7 批次拆分：100 → 60 + 40，子批次沿原单关联');
        $o7 = $svc->createOrder($eng, ['order_no' => 'MO-VERIFY-07', 'product_code' => $product,
            'product_name' => '验证单7', 'planned_qty' => 100]);
        $svc->releaseOrder($o7, $eng);
        $b7 = Batch::where('production_order_id', $o7->id)->first();
        $this->expectFail('拆分为 60+30（≠100）被拒绝', function () use ($svc, $b7, $eng) {
            $svc->split($b7, $eng, [60, 30]);
        });
        $children = $svc->split($b7, $eng, [60, 40]);
        $b7 = $b7->fresh();
        $this->check('父批次标记 split/0，两子批 60/40 同属原单同根',
            $b7->status === Batch::SPLIT && $b7->qty === 0
            && $children[0]->qty === 60 && $children[1]->qty === 40
            && $children[0]->root_batch_id === $b7->root_batch_id
            && $children[1]->production_order_id === $o7->id);
        // 子批继续各自完工
        foreach ($children as $ch) {
            $svc->receive($ch, $cut);
            $svc->registerProduction($ch->fresh(), $cut, $this->reg($ch->fresh(), $ch->qty, 0, 0));
            $ch = $ch->fresh();
            $svc->inspect($ch->currentRecord, $qc, ['passed_qty' => $ch->qty, 'rework_qty' => 0, 'scrap_qty' => 0]);
            $svc->receive($ch->fresh(), $cnc);
            $svc->registerProduction($ch->fresh(), $cnc, $this->reg($ch->fresh(), $ch->qty, 0, 0));
            $ch = $ch->fresh();
            $svc->inspect($ch->currentRecord, $qc, ['passed_qty' => $ch->qty, 'rework_qty' => 0, 'scrap_qty' => 0]);
            $svc->receive($ch->fresh(), $weld);
            $svc->registerProduction($ch->fresh(), $weld, $this->reg($ch->fresh(), $ch->qty, 0, 0));
            $ch = $ch->fresh();
            $svc->inspect($ch->currentRecord, $qc, ['passed_qty' => $ch->qty, 'rework_qty' => 0, 'scrap_qty' => 0]);
        }
        $this->check('两个拆分子批均完工（60+40）',
            $children[0]->fresh()->status === Batch::COMPLETED
            && $children[1]->fresh()->status === Batch::COMPLETED);
        $this->reconcile($o7);

        /* ---------- 场景 8：参数换版不影响在制批次 ---------- */
        $this->section('场景8 参数换版：在制批次保留 v1，新接收批次锁定 v2');
        $o8 = $svc->createOrder($eng, ['order_no' => 'MO-VERIFY-08', 'product_code' => $product,
            'product_name' => '验证单8', 'planned_qty' => 30]);
        $svc->releaseOrder($o8, $eng);
        $b8 = Batch::where('production_order_id', $o8->id)->first();
        $svc->receive($b8, $cut); // 锁定 OP-10 v1
        $b8 = $b8->fresh();
        $lockedV1Id = $b8->param_version_id;

        // 工程师为 OP-10 创建并激活 v2（收紧温度上限）
        $v2 = ParameterVersion::create([
            'process_id' => $p10->id, 'version' => 'v2', 'status' => 'draft',
            'change_note' => '换版：切割温度上限收紧至 60', 'created_by' => $eng->id,
        ]);
        $tpls = \App\Models\ParameterTemplate::where('process_id', $p10->id)->get()->keyBy('code');
        $v1 = ParameterVersion::with('items')->find($lockedV1Id);
        foreach ($v1->items as $it) {
            $tpl = $it->template;
            $max = $tpl->code === 'CUT_TEMP' ? 60 : $it->max_value;
            $v2->items()->create([
                'parameter_template_id' => $it->parameter_template_id,
                'set_value' => $it->set_value, 'min_value' => $it->min_value, 'max_value' => $max,
            ]);
        }
        ParameterVersion::where('process_id', $p10->id)->where('status', 'active')->update(['status' => 'retired']);
        $v2->update(['status' => 'active', 'activated_at' => now()]);

        $this->check('v1 已退役、v2 已启用',
            ParameterVersion::find($lockedV1Id)->status === 'retired'
            && $v2->fresh()->status === 'active');
        $this->check('在制批次仍锁定 v1（参数锁定不被换版打断）', $b8->fresh()->param_version_id === $lockedV1Id);

        // 新批次接收 OP-10 应锁 v2
        $o8b = $svc->createOrder($eng, ['order_no' => 'MO-VERIFY-08B', 'product_code' => $product,
            'product_name' => '验证单8B', 'planned_qty' => 10]);
        $svc->releaseOrder($o8b, $eng);
        $b8b = Batch::where('production_order_id', $o8b->id)->first();
        $svc->receive($b8b, $cut);
        $this->check('换版后新接收批次锁定 v2', $b8b->fresh()->paramVersion?->version === 'v2');

        // 在制 b8 用 v1 范围登记：CUT_TEMP=70（v1 内 20-80，v2 下超限），按 v1 应通过
        $params70 = $this->paramsAtSet($b8->fresh(), ['CUT_TEMP' => 70.0]);
        $svc->registerProduction($b8->fresh(), $cut, array_merge($this->reg($b8->fresh(), 30, 0, 0), ['actual_params' => $params70]));
        $b8 = $b8->fresh();
        $this->check('在制批次按锁定的 v1 判定：70℃ 合格进入待质检（v2 下本应超限）',
            $b8->status === Batch::QC_PENDING);
        $this->reconcile($o8);

        /* ---------- 汇总 ---------- */
        $this->newLine();
        $this->info("结果：通过 {$this->pass} 项，失败 {$this->fail} 项");
        $this->newLine();
        $this->line('追溯链抽样（MO-VERIFY-06 事件流）：');
        $events = \App\Models\TraceEvent::where('production_order_id', $o6->id)
            ->orderBy('occurred_at')->orderBy('id')->get();
        foreach ($events as $e) {
            $batchNo = $e->batch?->batch_no ?? '-';
            $this->line(sprintf('  %-18s 批次%-16s %s → %s  qty=%s',
                $e->event_type, $batchNo, $e->from_node ?? '·', $e->to_node ?? '·', $e->quantity ?? '-'));
        }

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** 合格/返工/报废登记数据（设备取工位首台） */
    private function reg(Batch $b, int $good, int $rework, int $scrap): array
    {
        $equipment = $b->station->equipment()->first();
        return [
            'equipment_id' => $equipment->id,
            'good_qty' => $good, 'rework_qty' => $rework, 'scrap_qty' => $scrap,
            'shift' => 'DAY',
        ];
    }

    /** 取锁定版本各参数设定值，并覆盖指定项（其余在范围内） */
    private function paramsAtSet(Batch $b, array $overrides): array
    {
        $params = [];
        foreach ($b->paramVersion->items as $item) {
            $params[$item->template->code] = (float) $item->set_value;
        }
        return array_merge($params, $overrides);
    }

    private function check(string $name, bool $ok): void
    {
        if ($ok) {
            $this->pass++;
            $this->line('  <fg=green>✓</> '.$name);
        } else {
            $this->fail++;
            $this->line('  <fg=red>✗ 失败：</> '.$name);
        }
    }

    private function expectFail(string $name, callable $fn): void
    {
        try {
            $fn();
            $this->check($name.'（应被拒绝但未拒绝）', false);
        } catch (BusinessException $e) {
            $this->check($name, true);
            $this->line('      └ 返回：'.$e->getMessage());
        }
    }

    private function section(string $t): void
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>'.$t.'</>');
    }

    private function reconcile(ProductionOrder $order): void
    {
        $summary = $order->fresh()->quantitySummary();
        $ok = $summary['planned_qty'] === $summary['wip_qty'] + $summary['completed_qty'] + $summary['scrapped_qty'];
        $this->check(sprintf('%s 守恒：计划%d = 在制%d + 完工%d + 报废%d',
            $order->order_no, $summary['planned_qty'], $summary['wip_qty'],
            $summary['completed_qty'], $summary['scrapped_qty']), $ok);
    }
}
