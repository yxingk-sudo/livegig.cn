# 用户端职位更新功能修复说明

## 问题描述
在[/user/personnel.php](file:///www/wwwroot/livegig.cn/user/personnel.php)页面直接更新人员现有职位时，会提示"更新职位失败: 未找到相关记录或职位未发生变化"。

## 问题原因分析
1. 用户端的职位更新逻辑与管理员端不同
2. 当用户尝试更新一个未分配到当前项目的人员职位时，系统会返回错误
3. 原来的逻辑没有处理人员未分配到项目的情况

## 解决方案
修改[/user/ajax/update_position.php](file:///www/wwwroot/livegig.cn/user/ajax/update_position.php)文件，当人员未分配到当前项目时，自动为其创建项目分配记录：

### 主要修改内容：
1. 当更新职位时，如果未找到相关记录：
   - 检查人员是否在当前项目中
   - 如果不在项目中，自动为其创建项目分配记录
   - 使用项目中的默认部门（按排序顺序的第一个部门）
   - 设置指定的职位

2. 改进了错误处理和用户反馈：
   - 提供更明确的错误消息
   - 在适当情况下自动创建必要的记录

### 技术实现细节：

```php
// 如果没有更新任何记录，检查人员是否在项目中
if ($affected_rows == 0) {
    // 检查人员是否在当前项目中
    $check_sql = "SELECT COUNT(*) as count FROM project_department_personnel 
                 WHERE personnel_id = :personnel_id AND project_id = :project_id";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->bindParam(':personnel_id', $personnel_id, PDO::PARAM_INT);
    $check_stmt->bindParam(':project_id', $project_id, PDO::PARAM_INT);
    $check_stmt->execute();
    $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    // 如果人员不在项目中，自动为其创建项目分配记录
    if ($result['count'] == 0) {
        // 获取默认部门（项目中的第一个部门）
        $dept_sql = "SELECT id FROM departments WHERE project_id = :project_id ORDER BY sort_order ASC LIMIT 1";
        $dept_stmt = $pdo->prepare($dept_sql);
        $dept_stmt->bindParam(':project_id', $project_id, PDO::PARAM_INT);
        $dept_stmt->execute();
        $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($dept_result) {
            // 为人员创建项目分配记录
            $insert_sql = "INSERT INTO project_department_personnel 
                          (project_id, department_id, personnel_id, position) 
                          VALUES (:project_id, :department_id, :personnel_id, :position)";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->bindParam(':project_id', $project_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':department_id', $dept_result['id'], PDO::PARAM_INT);
            $insert_stmt->bindParam(':personnel_id', $personnel_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':position', $position, PDO::PARAM_STR);
            $insert_stmt->execute();
            
            $affected_rows = 1; // 标记为成功更新
        } else {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'message' => '项目中未找到部门，无法为人员创建项目分配'
            ]);
            exit;
        }
    }
}
```

## 修改效果
1. 用户现在可以直接更新任何人员的职位，即使该人员之前未分配到当前项目
2. 系统会自动为未分配的人员创建项目分配记录
3. 提供了更友好的错误消息和处理机制
4. 保持了数据的一致性和完整性

## 测试建议
1. 访问[/user/personnel.php](file:///www/wwwroot/livegig.cn/user/personnel.php)页面
2. 选择一个项目（如果有项目上下文）
3. 尝试更新一个已分配到项目中的人员职位
4. 尝试更新一个未分配到项目中的人员职位
5. 验证职位是否正确更新
6. 检查数据库中的[project_department_personnel](file:///www/wwwroot/livegig.cn/project_department_personnel.sql)表是否正确创建了新的分配记录

## 注意事项
- 此修改仅影响用户端的职位更新功能
- 管理员端的逻辑保持不变
- 系统会使用项目中的默认部门进行分配
- 修改后需要重启Web服务器以确保生效