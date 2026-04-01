# 车辆分配表格行程行样式区分功能

**日期**: 2026-02-27  
**文件**: `/admin/transportation_statistics.php`  
**状态**: ✅ 完成

## 变更概述

为车辆分配详情表格添加了**车辆级别的视觉样式区分**功能，使得每辆车的所有行程行采用统一的独特样式，实现了不同车辆之间的清晰视觉分组。

**文件行数变化**: 2023 行 → 2151 行 (+128 行)

## 核心功能

### 1. 车辆颜色分配系统

为每辆车自动分配唯一的颜色标识，支持最多 **6 辆车**的视觉区分：

| 车辆顺序 | 颜色系 | 颜色代码 | 示例 |
|--------|-------|--------|------|
| 1号车 | 蓝色系 | #11a1fd | ![#11a1fd](https://via.placeholder.com/20/11a1fd) |
| 2号车 | 绿色系 | #00c569 | ![#00c569](https://via.placeholder.com/20/00c569) |
| 3号车 | 橙色系 | #ffc833 | ![#ffc833](https://via.placeholder.com/20/ffc833) |
| 4号车 | 红色系 | #f46363 | ![#f46363](https://via.placeholder.com/20/f46363) |
| 5号车 | 紫色系 | #5a75f9 | ![#5a75f9](https://via.placeholder.com/20/5a75f9) |
| 6号车 | 青色系 | #0dcaf0 | ![#0dcaf0](https://via.placeholder.com/20/0dcaf0) |

超过 6 辆车时，颜色循环使用（7 号车采用 1 号车的颜色，以此类推）。

### 2. 样式应用层级

每辆车的样式应用于三个层级，形成统一的视觉标识：

#### 2.1 表格行级别 (4px 左边框)
```css
.vehicle-trip-group-N {
    background-color: rgba(R, G, B, 0.05);  /* 淡色背景 */
    border-left: 4px solid #RRGGBB;         /* 彩色边框 */
}
```
- **作用**: 整行底色和左边框
- **效果**: 快速识别该行属于哪辆车

#### 2.2 行程项级别 (3px 左边框)
```css
.vehicle-trip-group-N .trip-item {
    border-left: 3px solid #RRGGBB !important;
    background-color: rgba(R, G, B, 0.06) !important;
}
```
- **作用**: 出行安排卡片的边框和背景
- **效果**: 强调行程信息所属车辆

#### 2.3 乘客组级别 (3px 左边框)
```css
.vehicle-trip-group-N .trip-passengers-group {
    border-left: 3px solid #RRGGBB;
    background-color: rgba(R, G, B, 0.05);
}
```
- **作用**: 乘客列表的边框和背景
- **效果**: 确保乘客信息也被正确着色

### 3. 高行程行加深

为区分普通行和高行程行，使用更深的背景色：

```css
.vehicle-trip-group-N.vehicle-trips-row {
    background-color: rgba(R, G, B, 0.08);  /* 深于单行 */
}
```

- **普通空闲车辆行**: `opacity 0.05` (最淡)
- **行程行**: `opacity 0.08` (中等深度) ← 包含此类

这样可以在视觉上快速区分空闲车和在役车。

## PHP 实现细节

### 3.1 颜色映射初始化

在表格前端添加了颜色分配逻辑：

```php
// 为每辆车分配颜色样式索引（用于行程行的视觉区分）
$vehicle_style_index = 0;
$vehicle_color_map = []; // 存储车辆ID到颜色索引的映射

foreach ($grouped_vehicles as $vehicle_id => $group) {
    $vehicle_color_map[$vehicle_id] = $vehicle_style_index % 6; // 6种颜色循环
    $vehicle_style_index++;
    // ... 其他统计逻辑
}
```

**关键特性**:
- `% 6`: 实现颜色循环，支持超过 6 辆车
- 保存在 `$vehicle_color_map` 数组中，供后续行生成使用

### 3.2 行生成时应用样式

在遍历车辆和行程时，为每行应用对应的样式类：

```php
foreach ($grouped_vehicles as $vehicle_id => $group):
    // ...
    $color_index = $vehicle_color_map[$vehicle_id];
    $vehicle_style_class = 'vehicle-trip-group-' . ($color_index + 1);
    // vehicle-trip-group-1 到 vehicle-trip-group-6
    
    // 空闲车辆行
    <tr class="vehicle-row <?php echo $vehicle_style_class; ?>">
    
    // 行程行
    <tr class="vehicle-row <?php echo $vehicle_style_class; ?> vehicle-trips-row">
```

## 视觉效果示例

### 场景：3 辆车，第 1 辆车 2 个行程，第 2 辆车 1 个行程，第 3 辆车 0 个行程（空闲）

```
┌─────────────────────────────────────────────────────┐
│ 1号车 - 蓝色系（2 个行程）                          │
├──────┬────────────┬────────┬────────┬─────────┬──────┤
│ FL01 │ 鲁A123...  │ 张三   │已分配  │ 02-27... │ 王五 │ ← 蓝色背景 + 蓝色左边框
│      │            │        │2个行程 │ 航空→酒  │ 赵六 │
├──────┼────────────┼────────┼────────┼─────────┼──────┤
│      │            │        │        │ 02-28... │ 孙七 │ ← 蓝色背景 + 蓝色左边框（更深）
│      │            │        │        │ 酒店→航  │ 钱九 │
├─────────────────────────────────────────────────────┤
│ 2号车 - 绿色系（1 个行程）                          │
├──────┬────────────┬────────┬────────┬─────────┬──────┤
│ FL02 │ 鲁B456...  │ 李四   │已分配  │ 02-27... │ 周十 │ ← 绿色背景 + 绿色左边框
│      │            │        │1个行程 │ 航空→酒  │ 郑十 │
├─────────────────────────────────────────────────────┤
│ 3号车 - 橙色系（0 个行程 - 空闲）                   │
├──────┬────────────┬────────┬────────┬─────────┬──────┤
│ FL03 │ 鲁C789...  │ 周八   │ 空闲   │ 暂无... │  -   │ ← 橙色背景 + 橙色左边框
└──────┴────────────┴────────┴────────┴─────────┴──────┘
```

## CSS 样式总览

### 颜色应用层级（以 1 号车蓝色为例）

| 元素 | 背景颜色 | 左边框 | 类名 |
|------|---------|--------|------|
| 整行 (空闲) | rgba(17,161,253,0.05) | 4px #11a1fd | `.vehicle-trip-group-1` |
| 整行 (行程) | rgba(17,161,253,0.08) | 4px #11a1fd | `.vehicle-trip-group-1.vehicle-trips-row` |
| 行程卡片 | rgba(17,161,253,0.06) | 3px #11a1fd | `.vehicle-trip-group-1 .trip-item` |
| 乘客组 | rgba(17,161,253,0.05) | 3px #11a1fd | `.vehicle-trip-group-1 .trip-passengers-group` |

类似的模式应用于其他 5 种颜色（`vehicle-trip-group-2` 至 `vehicle-trip-group-6`）。

## 样式特性说明

### 为什么使用透明度背景而不是纯色？

1. **可读性**: 透明度背景不会遮挡文本，文本对比度良好
2. **细节保留**: 表格边框、文本细节仍清晰可见
3. **现代感**: 与 Espire 设计系统的透明/玻璃态风格一致
4. **灵活性**: 易于调整透明度值以改变强调程度

### 为什么有三种边框？

| 边框 | 宽度 | 颜色 | 用途 |
|------|------|------|------|
| 表格行左边框 | 4px | 各车颜色 | 表行级识别 |
| 行程卡片左边框 | 3px | 各车颜色 | 出行信息识别 |
| 乘客组左边框 | 3px | 各车颜色 | 乘客信息识别 |

多层边框确保用户在扫视表格时，无论关注哪个部分（车辆、行程或乘客），都能立即识别所属车辆。

## 响应式适配

样式自动适配所有响应式断点（1200px, 992px, 768px, 576px），无需额外修改。

## 性能影响

- **CSS 大小**: 添加了 122 行 CSS 规则
- **PHP 处理**: 增加了 2 个 PHP 变量和 7 行初始化代码
- **渲染性能**: 无影响，仅使用 CSS 类名，不涉及 JavaScript 或 DOM 操作

## 验证结果

✅ **PHP 语法检查**: No syntax errors detected  
✅ **文件行数**: 2151 行（从 2023 行）  
✅ **CSS 样式**: 6 种颜色方案完整实现  
✅ **样式应用**: 所有 3 个层级（表行、行程、乘客）已着色  

## 回滚方法

如需回滚此次修改：

```bash
# 从备份恢复
cp /www/wwwroot/livegig.cn/backupfiles/admin/transportation_statistics.php \
   /www/wwwroot/livegig.cn/admin/transportation_statistics.php
```

## 后续改进建议

1. **动态颜色数量**: 可根据车辆数量动态调整颜色方案（目前固定 6 种）
2. **用户自定义**: 允许用户自定义车辆颜色
3. **颜色查询表**: 在表格上方显示车辆-颜色对应关系
4. **深色模式**: 为深色主题提供相应的颜色方案

## 相关 CSS 类名

| 类名 | 用途 |
|------|------|
| `.vehicle-trip-group-1` ~ `.vehicle-trip-group-6` | 车辆颜色分组 |
| `.vehicle-trips-row` | 区分行程行与空闲行 |
| `.trip-item` | 行程卡片容器 |
| `.trip-passengers-group` | 乘客信息容器 |

---

**修改人**: AI Code Assistant  
**修改时间**: 2026-02-27  
**验证状态**: ✅ PHP Lint 通过  
**变更内容**: +7 行 PHP 逻辑 + 122 行 CSS 样式
