# 人员选择方式按钮颜色优化说明

## 优化目标
优化[/user/batch_meal_order.php](file:///www/wwwroot/livegig.cn/user/batch_meal_order.php)页面中人员选择方式的按钮颜色，使用不同颜色来区分"按部门选择"和"按个人选择"，提高用户界面的直观性和用户体验。

## 优化内容

### 1. 按钮颜色优化
- **按部门选择按钮**：使用绿色系(btn-outline-success)
- **按个人选择按钮**：使用蓝色系(btn-outline-primary)

### 2. 悬停和选中状态优化
- 添加了专门的CSS样式来处理按钮的悬停和选中状态
- 按部门选择按钮在悬停或选中时显示深绿色背景
- 按个人选择按钮在悬停或选中时显示深蓝色背景
- 两种按钮在激活状态下都显示白色文字以确保良好的对比度

### 3. 视觉效果增强
- 通过颜色区分使两种选择方式更加直观
- 悬停效果提供更好的用户交互反馈
- 选中状态的高亮显示帮助用户明确当前选择

## 技术实现

### HTML修改
```html
<input type="radio" class="btn-check" name="selection_type" value="department" id="selectDepartment" required>
<label class="btn btn-outline-success" for="selectDepartment">
    <i class="bi bi-people-fill me-2"></i>按部门选择
</label>

<input type="radio" class="btn-check" name="selection_type" value="individual" id="selectIndividual" required>
<label class="btn btn-outline-primary" for="selectIndividual">
    <i class="bi bi-person-check-fill me-2"></i>按个人选择
</label>
```

### CSS样式增强
```css
/* 人员选择方式按钮样式优化 */
#selectDepartment:checked + .btn-outline-success,
.btn-outline-success:hover {
    background-color: #198754;
    border-color: #198754;
    color: white;
}

#selectIndividual:checked + .btn-outline-primary,
.btn-outline-primary:hover {
    background-color: #0d6efd;
    border-color: #0d6efd;
    color: white;
}
```

## 修改效果
1. 用户可以更直观地区分两种人员选择方式
2. 通过颜色编码提供更好的视觉引导
3. 增强了用户界面的美观性和专业性
4. 提高了用户操作的准确性和效率

## 测试建议
1. 访问[/user/batch_meal_order.php](file:///www/wwwroot/livegig.cn/user/batch_meal_order.php)页面
2. 验证人员选择方式按钮的颜色是否正确显示
3. 测试按钮的悬停效果
4. 测试按钮的选中状态效果
5. 确认两种选择方式的功能是否正常工作

## 注意事项
- 此修改仅优化了按钮的视觉样式，未改变任何功能逻辑
- 修改后需要重启Web服务器以确保生效
- 建议在测试环境中验证修改效果后再部署到生产环境