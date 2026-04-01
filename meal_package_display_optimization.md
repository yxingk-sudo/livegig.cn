# 报餐记录表套餐统计列显示优化

## 修改目的
优化 [/user/meals_new.php](file:///www/wwwroot/livegig.cn/user/meals_new.php) 报餐记录表的套餐统计列显示效果，增大套餐名字字体并去除徽章样式。

## 修改内容
1. 增大套餐名字字体
2. 去除徽章样式

## 修改位置
文件：[/user/meals_new.php](file:///www/wwwroot/livegig.cn/user/meals_new.php)
位置：按日统计表格中的套餐统计列（约第591行开始）

## 修改前代码
```php
<td>
    <?php if ($meal['package_names']): ?>
        <?php 
        $packages = explode(',', $meal['package_names']);
        foreach ($packages as $package): 
        ?>
            <span class="badge bg-success me-1 mb-1">
                <?php echo htmlspecialchars($package); ?>
            </span>
        <?php endforeach; ?>
    <?php else: ?>
        <span class="text-muted">无套餐</span>
    <?php endif; ?>
</td>
```

## 修改后代码
```php
<td>
    <?php if ($meal['package_names']): ?>
        <?php 
        $packages = explode(',', $meal['package_names']);
        foreach ($packages as $package): 
        ?>
            <span class="me-2 mb-1" style="font-size: 1.1em; font-weight: 500;">
                <?php echo htmlspecialchars($package); ?>
            </span>
        <?php endforeach; ?>
    <?php else: ?>
        <span class="text-muted">无套餐</span>
    <?php endif; ?>
</td>
```

## 修改说明
1. 移除了 `badge bg-success` 类，去除了徽章样式
2. 通过 `style="font-size: 1.1em; font-weight: 500;"` 增大了字体并稍微加粗
3. 保留了 `me-2 mb-1` 类用于元素间距控制
4. 保持了原有的功能逻辑不变

## 效果
- 套餐名称显示更加清晰易读
- 去除了徽章样式，使界面更加简洁
- 保持了良好的视觉层次和间距