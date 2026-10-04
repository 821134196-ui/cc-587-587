# 工厂半成品转序与工艺参数锁定系统

操作员完成当前工序后登记产出,质检确认放行,下一工位接收时可查到该批半成品实际锁定的工艺参数版本。拆分与返工沿原生产单保存关联,全链数量守恒可核对。

## 一键启动

```bash
./start.sh
```

- 前端界面: http://127.0.0.1:5173
- 后端 API : http://127.0.0.1:8000/api

> 环境无系统 PHP 时,脚本自动使用 `~/.runtime/php` 静态运行时(PHP 8.4)。
> SQLite 数据库文件:`server/database/database.sqlite`,首次启动自动迁移并载入演示数据。

## 检验测试(数量核对 / 参数换版 / 返工回流 / 追溯链)

```bash
cd server && php artisan test
```

22 个特性测试覆盖:

| 检验点 | 测试 |
|---|---|
| 数量不符 → 停在待处理,不得转序 | `QuantityReconciliationTest` |
| 拆分/报废/返工后数量与原记录一致 | `QuantityReconciliationTest` |
| 参数换版后旧批次保留实际使用版本 | `ParameterVersioningTest` |
| 读数按锁定版本判定,超限 → 待处理 | `ParameterVersioningTest` |
| 返工回流沿原生产单关联、数量守恒 | `ReworkFlowTest` |
| 追溯链完整(树/事件/全链核对) | `TraceabilityTest` |
| 操作员不能批准自己生产的批次 | `ApprovalAndConcurrencyTest` |
| 两个工位不能同时占用同一批(原子占位) | `ApprovalAndConcurrencyTest` |

## 业务规则

- **批次状态机**: `在制 → 待质检 → 已放行 → (下一工序接收)在制 … → 已完成`;异常进入 `待处理`;拆分后父批为 `已拆分`。
- **数量核对**: 登记产出时 `合格 + 返工 + 报废 = 本工序投入`,不符则停在待处理,修正前不得质检、不得转序。
- **参数锁定**: 批次进入工序时锁定设备当时 `active` 的参数版本;换版后旧版本作废,但在制批次仍按锁定版本判定读数。
- **参数超限**: 登记产出时按锁定版本采集设备读数(本地模拟),任一指标超限 → 待处理。
- **审批约束**: 质检人不能是产出登记人(不能自批);接收通过原子状态翻转实现,两个工位同时接收只有一方成功。
- **拆分**: 子批数量之和必须等于父批在手数量,子批继承父批锁定的参数版本,沿原生产单关联。
- **返工**: 从登记的返工数量中抽出部分生成返工子批,回流到当前或之前工序;接收时若仍有未处理返工,自动生成返工子批留在原工序,保证追溯链不断。
- **全链守恒**: `投入 = 在手 + 完成入库 + 累计报废`,追溯接口逐批校验并给出核对结果。

## 主要 API

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/process-map` | 工序图(各工序在制批次、设备、参数版本) |
| POST | `/api/orders` | 创建生产单并可投产首批 |
| POST | `/api/batches/{id}/output` | 登记产出(触发读数采集与数量/参数校验) |
| POST | `/api/batches/{id}/qc` | 质检 `release` / `reject` |
| POST | `/api/batches/{id}/receive` | 下一工位接收(锁定参数版本) |
| POST | `/api/batches/{id}/split` | 拆分批次 |
| POST | `/api/batches/{id}/rework` | 返工回流 |
| GET | `/api/batches/{id}/trace` | 追溯链(批次树 + 事件 + 数量核对) |
| POST | `/api/equipments/{id}/parameter-versions` | 参数换版 |

写操作需携带请求头 `X-User-Id`(前端右上角切换用户自动携带)。

## 目录结构

```
server/     Laravel 后端(Eloquent + SQLite)
  app/Models           工序/设备/参数版本/生产单/批次/事件/读数
  app/Services         BatchService(流转规则) TraceService(追溯核对) DeviceSimulator(读数模拟)
  tests/Feature        检验测试
frontend/   React 前端(Vite)
  src/pages            工序图 / 批次台账 / 生产单 / 参数版本
  src/BatchDetail.jsx  批次详情抽屉(操作 + 追溯链)
start.sh    一键启动
```
