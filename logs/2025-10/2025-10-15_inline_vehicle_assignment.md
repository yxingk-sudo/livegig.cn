# 交通报告页面内联车辆分配功能实现

## 任务概述
**日期**: 2025-10-15  
**任务**: 在 `/admin/transportation_reports.php` 页面实现内联车辆分配功能  
**状态**: ✅ 完成

## 需求说明
将 `/admin/transportation_reports.php?project_id=4` 页面中的"分配车辆"按钮功能替换为与 `/admin/assign_fleet.php` 页面中"操作"列下的"分配车辆"按钮完全相同的功能。要求：
1. 不跳转页面，直接在当前页面完成车辆分配操作
2. 点击按钮后显示下拉菜单以选择车辆
3. 选择车辆后确认分配

## 实施方案

### 1. 前端界面修改
**文件**: `/admin/transportation_reports.php`

#### 修改内容：
将原有的跳转链接按钮替换为内联下拉选择表单：

**修改前**：
```php
<div class="d-grid">
    <a href="assign_fleet.php?project_id=<?php echo $report['project_id']; ?>&transportation_id=<?php echo $report['id']; ?>" 
       class="btn btn-success btn-sm assign-btn">
        <i class="bi bi-plus-circle"></i> 分配车辆
    </a>
</div>
```

**修改后**：
```php
<div class="vehicle-assign-inline">
    <select class="form-select form-select-sm mb-2" 
            id="vehicle-select-<?php echo $report['id']; ?>"
            style="font-size: 11px;">
        <option value="">选择车辆...</option>
        <?php
        // 获取可用车辆列表
        $fleet_query = "SELECT id, fleet_number, vehicle_type, vehicle_model, license_plate, driver_name, seats 
                       FROM fleet 
                       WHERE project_id = :project_id AND status = 'active' 
                       ORDER BY fleet_number ASC";
        $fleet_stmt = $db->prepare($fleet_query);
        $fleet_stmt->bindParam(':project_id', $report['project_id']);
        $fleet_stmt->execute();
        $available_vehicles = $fleet_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($available_vehicles as $vehicle):
        ?>
            <option value="<?php echo $vehicle['id']; ?>">
                <?php echo htmlspecialchars($vehicle['fleet_number']); ?> - 
                <?php echo htmlspecialchars($vehicle['license_plate']); ?> - 
                <?php echo htmlspecialchars($vehicle['driver_name'] ?? '无司机'); ?> - 
                <?php echo htmlspecialchars($vehicle['seats']); ?>座
            </option>
        <?php endforeach; ?>
    </select>
    <button type="button" 
            class="btn btn-success btn-sm assign-btn w-100" 
            onclick="assignVehicleInline(<?php echo $report['project_id']; ?>, <?php echo $report['id']; ?>)"
            style="font-size: 11px; padding: 6px 12px;">
        <i class="bi bi-check-circle"></i> 确认分配
    </button>
</div>
```

### 2. 后端逻辑实现
**文件**: `/admin/transportation_reports.php`

#### 新增 POST 处理逻辑：

```php
if ($action === 'assign_vehicle') {
    // 处理车辆分配
    $transportation_id = intval($_POST['transportation_id'] ?? 0);
    $fleet_id = intval($_POST['fleet_id'] ?? 0);
    $project_id = intval($_POST['project_id'] ?? 0);
    
    if ($transportation_id && $fleet_id && $project_id) {
        try {
            // 获取出行记录信息
            $query = "SELECT passenger_count FROM transportation_reports WHERE id = :id AND project_id = :project_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $transportation_id);
            $stmt->bindParam(':project_id', $project_id);
            $stmt->execute();
            $transport_record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transport_record) {
                $error = '出行记录不存在';
            } else {
                $passenger_count = $transport_record['passenger_count'];
                
                // 获取车辆座位数
                $query = "SELECT seats FROM fleet WHERE id = :id AND project_id = :project_id AND status = 'active'";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $fleet_id);
                $stmt->bindParam(':project_id', $project_id);
                $stmt->execute();
                $fleet_record = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$fleet_record) {
                    $error = '车辆不存在或不可用';
                } else {
                    $vehicle_seats = $fleet_record['seats'];
                    
                    // 检查车辆是否已分配给该出行记录
                    $query = "SELECT COUNT(*) FROM transportation_fleet_assignments 
                             WHERE transportation_report_id = :transportation_id AND fleet_id = :fleet_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':transportation_id', $transportation_id);
                    $stmt->bindParam(':fleet_id', $fleet_id);
                    $stmt->execute();
                    
                    if ($stmt->fetchColumn() > 0) {
                        $error = '该车辆已分配给此出行记录';
                    } else {
                        // 计算已分配车辆的总座位数
                        $query = "SELECT COALESCE(SUM(f.seats), 0) as total_seats 
                                 FROM transportation_fleet_assignments tfa 
                                 JOIN fleet f ON tfa.fleet_id = f.id 
                                 WHERE tfa.transportation_report_id = :transportation_id";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':transportation_id', $transportation_id);
                        $stmt->execute();
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        $current_total_seats = $result['total_seats'];
                        
                        // 计算剩余需要分配的乘客数量
                        $remaining_passengers = max(0, $passenger_count - $current_total_seats);
                        
                        // 分配车辆
                        $query = "INSERT INTO transportation_fleet_assignments (transportation_report_id, fleet_id) VALUES (:transportation_id, :fleet_id)";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':transportation_id', $transportation_id);
                        $stmt->bindParam(':fleet_id', $fleet_id);
                        
                        if ($stmt->execute()) {
                            // 根据座位数和乘客数给出提示
                            if ($vehicle_seats > $remaining_passengers * 2) {
                                $message = "车辆分配成功！提示：此车辆座位数({$vehicle_seats})远大于剩余乘客数量({$remaining_passengers})";
                            } elseif ($vehicle_seats < $remaining_passengers) {
                                $message = "车辆分配成功！提示：此车辆座位数({$vehicle_seats})不足以容纳所有剩余乘客({$remaining_passengers})，需要继续分配其他车辆";
                            } else {
                                $message = '车辆分配成功！';
                            }
                        } else {
                            $error = '车辆分配失败，请重试';
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $error = '分配车辆失败：' . $e->getMessage();
        }
    } else {
        $error = '参数错误';
    }
}
```

### 3. JavaScript 函数更新
**文件**: `/admin/transportation_reports.php`

#### 新增/修改函数：

```javascript
// 车辆分配功能 - 在当前页面完成分配
function assignVehicleInline(projectId, transportationId) {
    const selectElement = document.getElementById(`vehicle-select-${transportationId}`);
    const selectedVehicleId = selectElement.value;

    if (!selectedVehicleId) {
        alert('请选择一辆车辆进行分配！');
        return;
    }

    // 创建表单并提交
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="assign_vehicle">
        <input type="hidden" name="project_id" value="${projectId}">
        <input type="hidden" name="transportation_id" value="${transportationId}">
        <input type="hidden" name="fleet_id" value="${selectedVehicleId}">
    `;
    document.body.appendChild(form);
    form.submit();
}
```

## 功能特性

### 1. 智能座位匹配
- 自动计算已分配车辆的总座位数
- 计算剩余需要分配的乘客数量
- 根据座位数和乘客数给出智能提示：
  - 座位数远大于乘客数（2倍以上）：提示建议使用更小型车辆
  - 座位数不足：提示需要继续分配其他车辆
  - 座位数适配：显示分配成功

### 2. 重复检测
- 检查车辆是否已分配给该出行记录
- 防止重复分配同一车辆

### 3. 数据验证
- 验证出行记录存在性
- 验证车辆存在性和可用性（status = 'active'）
- 验证项目ID匹配

### 4. 用户体验优化
- 不跳转页面，在当前页面完成操作
- 下拉选择框显示完整车辆信息（车队编号、车牌号、司机、座位数）
- 分配后自动刷新页面显示最新状态
- 提供友好的成功/错误提示信息

## 数据库交互

### 涉及的表：
1. `transportation_reports` - 出行记录表
2. `fleet` - 车队管理表
3. `transportation_fleet_assignments` - 车辆分配关联表

### SQL 查询：
1. 获取出行记录乘客数量
2. 获取车辆座位数和状态
3. 检查车辆是否已分配
4. 计算已分配车辆总座位数
5. 插入新的车辆分配记录

## 测试建议

### 测试用例：
1. ✅ 正常分配：选择合适车辆进行分配
2. ✅ 重复分配检测：尝试分配已分配的车辆
3. ✅ 座位数提示：测试不同座位数场景的提示
4. ✅ 多次分配：为同一出行记录分配多辆车
5. ✅ 错误处理：无效的车辆ID、出行记录ID

## 与 assign_fleet.php 的对比

### 相同点：
- 相同的业务逻辑（座位匹配、重复检测）
- 相同的数据验证流程
- 相同的提示信息

### 不同点：
| 特性 | assign_fleet.php | transportation_reports.php (新) |
|------|-----------------|--------------------------------|
| 页面跳转 | 需要跳转到专门页面 | 在当前页面完成 |
| 操作步骤 | 2步：跳转→选择 | 1步：直接选择 |
| 界面集成 | 独立页面 | 内联在表格中 |
| 用户体验 | 需要来回跳转 | 流畅无跳转 |

## 文件变更清单

### 修改的文件：
1. `/admin/transportation_reports.php` (主要修改)
   - 新增 POST 处理逻辑（assign_vehicle action）
   - 修改前端界面（下拉选择表单）
   - 更新 JavaScript 函数

### 新增的文件：
- 无

## 回滚方案

如需回滚，可以：

1. **前端回滚**：将下拉选择表单替换回原来的链接按钮
```php
<div class="d-grid">
    <a href="assign_fleet.php?project_id=<?php echo $report['project_id']; ?>&transportation_id=<?php echo $report['id']; ?>" 
       class="btn btn-success btn-sm assign-btn">
        <i class="bi bi-plus-circle"></i> 分配车辆
    </a>
</div>
```

2. **后端回滚**：删除 `assign_vehicle` action 的处理逻辑

3. **JavaScript 回滚**：删除 `assignVehicleInline` 函数

## 兼容性说明

- ✅ 保持与现有数据库结构完全兼容
- ✅ 不影响 assign_fleet.php 页面功能
- ✅ 可以与 assign_fleet.php 同时使用
- ✅ 支持所有现代浏览器

## 后续优化建议

1. **AJAX 实现**：考虑使用 AJAX 提交，避免页面刷新
2. **实时验证**：选择车辆时即时显示座位匹配提示
3. **批量分配**：支持一次为多条出行记录分配车辆
4. **车辆筛选**：根据车型需求自动筛选合适车辆

## 验证结果

- ✅ 代码语法检查通过
- ✅ 数据库查询逻辑正确
- ✅ JavaScript 函数正确
- ✅ 与现有功能无冲突

## 总结

此次修改成功将 assign_fleet.php 的车辆分配功能完整移植到 transportation_reports.php 页面，实现了在不跳转页面的情况下完成车辆分配操作。用户体验得到显著提升，操作流程更加简洁高效。

---

**执行人**: AI Assistant  
**记录时间**: 2025-10-15  
**状态**: 已完成 ✅
