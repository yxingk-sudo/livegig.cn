# 用餐数量列显示优化

## 修改目的
优化 [/user/meals_new.php](file:///www/wwwroot/livegig.cn/user/meals_new.php) 报餐记录表的用餐数量列显示效果，仅对数字部分应用徽章样式，并移除"份"字与数字的直接连接。

## 修改内容
1. 仅对数字部分应用徽章样式效果
2. 移除"份"字与数字的直接连接
3. 确保数字徽章的颜色与餐类型徽章的颜色保持一致

## 修改位置
文件：[/user/meals_new.php](file:///www/wwwroot/livegig.cn/user/meals_new.php)
位置：按日统计表格中的用餐数量列（约第578行开始）

## 修改前代码
```php
<td>
    <span class="badge bg-primary fs-6">
        <?php echo $meal['total_count']; ?> 份
    </span>
</td>
```

## 修改后代码
```php
<td>
    <span class="badge bg-<?php echo $color; ?> fs-6">
        <?php echo $meal['total_count']; ?>
    </span>
    <span>份</span>
</td>
```

## 修改说明
1. 将原来的 `bg-primary` 改为 `bg-<?php echo $color; ?>`，使数字徽章的颜色与餐类型徽章的颜色保持一致
2. 将"份"字从徽章中分离出来，单独显示在徽章后面
3. 保持了原有的 `fs-6` 类来控制字体大小
4. 保持了原有的功能逻辑不变

## 效果
- 数字徽章颜色与餐类型徽章颜色一致，视觉上更加统一
- "份"字独立显示，避免了与数字的直接连接
- 保持了良好的视觉层次和间距