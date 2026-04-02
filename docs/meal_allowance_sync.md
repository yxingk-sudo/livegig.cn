# 餐费补助页面数据同步说明

## 问题描述
[/user/meal_allowance.php](file:///www/wwwroot/livegig.cn/user/meal_allowance.php) 页面未能同步 [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php) 页面的数据，特别是手动输入的天数数据。

## 问题原因
1. [/user/meal_allowance.php](file:///www/wwwroot/livegig.cn/user/meal_allowance.php) 页面没有查询和使用 `manual_meal_allowance_days` 表中的手动天数数据
2. 当用户在 [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php) 页面手动输入天数时，[/user/meal_allowance.php](file:///www/wwwroot/livegig.cn/user/meal_allowance.php) 页面无法显示这些数据

## 解决方案
修改 [/user/meal_allowance.php](file:///www/wwwroot/livegig.cn/user/meal_allowance.php) 文件，使其能够：
1. 查询 `manual_meal_allowance_days` 表中的手动天数数据
2. 在计算餐费补助时优先使用手动输入的天数
3. 确保与 [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php) 页面显示相同的数据

## 修改内容

### user/meal_allowance.php 文件修改
```php
// 新增代码逻辑：
// 1. 查询manual_meal_allowance_days表中的手动天数数据
// 2. 将手动天数数据转换为关联数组以便快速查找
// 3. 在处理没有酒店记录的人员时，使用手动输入的天数
```

具体修改：
1. 在 `getPersonnelAllowance` 函数中添加查询 `manual_meal_allowance_days` 表的逻辑
2. 将手动天数数据转换为关联数组以便快速查找
3. 在处理没有酒店记录的人员时，使用手动输入的天数而不是默认的0

## 业务逻辑说明

### 数据优先级
1. 如果 `manual_meal_allowance_days` 表中有记录，则使用该记录中的天数
2. 如果没有手动记录，则使用酒店记录中的天数
3. 如果既没有手动记录也没有酒店记录，则天数为0

### 同步机制
1. 两个页面都使用相同的数据库表和查询逻辑
2. 两个页面都遵循相同的数据优先级规则
3. 用户在任一页面输入的数据在另一个页面都能正确显示

## 测试验证
通过页面测试验证了以下内容：
1. 在 [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php) 页面手动输入天数后，[/user/meal_allowance.php](file:///www/wwwroot/livegig.cn/user/meal_allowance.php) 页面能正确显示相同的天数
2. 餐费补助金额在两个页面中计算结果一致
3. 对于没有酒店记录的人员，两个页面都显示手动输入的天数
4. 对于有酒店记录的人员，如果未手动输入天数，则显示酒店记录中的天数

## 效果
1. [/user/meal_allowance.php](file:///www/wwwroot/livegig.cn/user/meal_allowance.php) 页面现在能正确同步 [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php) 页面的数据
2. 手动输入的天数在两个页面中保持一致
3. 餐费补助金额在两个页面中计算结果一致
4. 用户在任一页面输入的数据在另一个页面都能正确显示