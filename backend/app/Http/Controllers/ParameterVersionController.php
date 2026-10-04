<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\ParameterTemplate;
use App\Models\ParameterVersion;
use App\Models\Process;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 工艺参数版本管理（工艺工程师）
 * 规则：版本激活后不可变；换版 = 新建版本并激活，旧版本自动 retired，
 * 但已经锁定旧版本的在制批次继续使用旧版本（参数锁定）。
 */
class ParameterVersionController extends Controller
{
    public function index(Request $request)
    {
        $processId = $request->query('process_id');
        $versions = ParameterVersion::with(['process:id,code,name,product_code', 'items.template', 'creator:id,name'])
            ->when($processId, fn ($q) => $q->where('process_id', $processId))
            ->orderByDesc('id')->get();
        return $versions;
    }

    public function show(ParameterVersion $parameterVersion)
    {
        return $parameterVersion->load(['process', 'items.template', 'creator:id,name']);
    }

    /** 工序的参数模板（参数项定义） */
    public function templates(Process $process)
    {
        return response()->json([
            'process' => $process->only('id', 'code', 'name', 'product_code', 'sequence'),
            'templates' => $process->templates()->get(),
            'active_version' => $process->versions()->with('items.template')->where('status', 'active')->first(),
        ]);
    }

    /**
     * 新建草稿版本：items 可直接提供；未提供时从当前 active 版本（或模板默认限）复制
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'process_id' => 'required|integer|exists:processes,id',
            'version' => 'required|string|max:40',
            'change_note' => 'nullable|string',
            'items' => 'array',
            'items.*.parameter_template_id' => 'required_with:items|integer|exists:parameter_templates,id',
            'items.*.set_value' => 'required_with:items|numeric',
            'items.*.min_value' => 'required_with:items|numeric',
            'items.*.max_value' => 'required_with:items|numeric',
            'activate' => 'boolean',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $process = Process::findOrFail($data['process_id']);

            if (ParameterVersion::where('process_id', $process->id)->where('version', $data['version'])->exists()) {
                throw new BusinessException("工序 {$process->code} 已存在版本 {$data['version']}");
            }

            $version = ParameterVersion::create([
                'process_id' => $process->id,
                'version' => $data['version'],
                'status' => 'draft',
                'change_note' => $data['change_note'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            if (! empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    if ($item['min_value'] > $item['max_value']) {
                        throw new BusinessException('参数下限不能大于上限');
                    }
                    if ($item['set_value'] < $item['min_value'] || $item['set_value'] > $item['max_value']) {
                        throw new BusinessException('设定值必须在上下限之内');
                    }
                    $version->items()->create($item);
                }
            } else {
                $this->copyItems($version, $process);
            }

            if (! empty($data['activate'])) {
                $this->activateVersion($version);
            }

            return response()->json($version->load('items.template'), 201);
        });
    }

    /** 激活版本：同工序其他 active 版本转为 retired（换版） */
    public function activate(ParameterVersion $parameterVersion)
    {
        return DB::transaction(function () use ($parameterVersion) {
            $v = ParameterVersion::query()->lockForUpdate()->findOrFail($parameterVersion->id);
            if ($v->status === 'active') {
                throw new BusinessException('该版本已经是启用状态');
            }
            if ($v->items()->count() === 0) {
                throw new BusinessException('版本没有参数项，不能启用');
            }
            $this->activateVersion($v);
            return $v->load('items.template');
        });
    }

    private function activateVersion(ParameterVersion $version): void
    {
        ParameterVersion::where('process_id', $version->process_id)
            ->where('status', 'active')->update(['status' => 'retired']);
        $version->update(['status' => 'active', 'activated_at' => now()]);
    }

    private function copyItems(ParameterVersion $version, Process $process): void
    {
        $active = $process->versions()->where('status', 'active')
            ->with('items')->orderByDesc('id')->first();
        if ($active) {
            foreach ($active->items as $item) {
                $version->items()->create([
                    'parameter_template_id' => $item->parameter_template_id,
                    'set_value' => $item->set_value,
                    'min_value' => $item->min_value,
                    'max_value' => $item->max_value,
                ]);
            }
            return;
        }
        foreach (ParameterTemplate::where('process_id', $process->id)->get() as $tpl) {
            $version->items()->create([
                'parameter_template_id' => $tpl->id,
                'set_value' => ($tpl->min_value + $tpl->max_value) / 2,
                'min_value' => $tpl->min_value,
                'max_value' => $tpl->max_value,
            ]);
        }
    }
}
