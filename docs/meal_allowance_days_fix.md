# 餐费补助天数输入修复说明

## 问题描述
在 [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php) 页面中，当用户手动填写天数时，无论人员是否有酒店记录，手动修改天数都会影响实际的酒店房晚数。

## 问题原因
1. 系统在处理天数修改时，会直接修改酒店记录中的退房日期
2. 这会导致实际的酒店房晚数发生变化
3. 用户希望天数仅用于餐费补助计算，而不影响实际的酒店房晚数

## 解决方案
1. 修改 [ajax_update_hotel_report_days.php](file:///www/wwwroot/livegig.cn/admin/ajax_update_hotel_report_days.php) 和 [ajax_create_hotel_report.php](file:///www/wwwroot/livegig.cn/admin/ajax_create_hotel_report.php) 文件
2. 无论人员是否有酒店记录，都将手动输入的天数保存到 `manual_meal_allowance_days` 表中
3. 不再修改任何现有的酒店记录
4. 修改 [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php) 文件，使其在计算餐费补助时优先使用手动输入的天数

## 修改内容

### ajax_update_hotel_report_days.php 文件修改
```php
// 新代码逻辑：
// 不再修改酒店记录中的退房日期
// 将手动输入的天数保存到manual_meal_allowance_days表中
```

具体修改：
1. 移除了修改 `hotel_reports` 表中退房日期的逻辑
2. 添加了将天数保存到 `manual_meal_allowance_days` 表的逻辑
3. 确保无论人员是否有酒店记录，都只影响餐费补助计算

### ajax_create_hotel_report.php 文件修改
```php
// 新代码逻辑：
// 不再创建任何酒店记录
// 将手动输入的天数保存到manual_meal_allowance_days表中
```

具体修改：
1. 移除了创建酒店记录的逻辑
2. 添加了将天数保存到 `manual_meal_allowance_days` 表的逻辑

### admin/meal_allowance.php 文件修改
1. 修改JavaScript代码，确保传递正确的参数给AJAX处理文件
2. 确保在计算餐费补助时优先使用手动输入的天数

## 业务逻辑说明

### 新的处理流程
1. 用户在天数输入框中输入天数
2. 系统将天数保存到 `manual_meal_allowance_days` 表中
3. 系统不修改任何现有的酒店记录
4. 餐费补助计算时使用 `manual_meal_allowance_days` 表中的天数
5. 实际的酒店房晚数保持不变

### 数据优先级
1. 如果 `manual_meal_allowance_days` 表中有记录，则使用该记录中的天数
2. 如果没有手动记录，则使用酒店记录中的天数
3. 如果既没有手动记录也没有酒店记录，则天数为0

## 测试验证
通过页面测试验证了以下内容：
1. 用户可以为有酒店记录的人员输入天数
2. 用户可以为没有酒店记录的人员输入天数
3. 系统正确将天数保存到 `manual_meal_allowance_days` 表中
4. 不再修改任何现有的酒店记录
5. 餐费补助计算时使用手动输入的天数
6. 实际的酒店房晚数保持不变

## 效果
1. 用户现在可以手动输入天数，且不会影响实际的酒店房晚数
2. 天数仅用于餐费补助计算
3. 无论人员是否有酒店记录，都遵循相同的处理逻辑
4. 餐费补助金额正确计算和显示
5. 页面刷新后仍能正确显示手动输入的天数和计算结果
6. 实际的酒店房晚数保持不变