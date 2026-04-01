#!/bin/bash
# 网站备份和清理Cron脚本

# 设置路径
SITE_PATH="/www/wwwroot/livegig.cn"
SCRIPTS_PATH="$SITE_PATH/scripts"
LOG_FILE="$SITE_PATH/logs/backup_cron.log"

# 创建日志目录（如果不存在）
mkdir -p "$SITE_PATH/logs"

# 记录开始时间
echo "$(date): 开始执行备份任务" >> "$LOG_FILE"

# 每周日凌晨2点执行完整备份
if [ "$(date +%u)" = "7" ] && [ "$(date +%H)" = "02" ]; then
    echo "$(date): 执行完整备份" >> "$LOG_FILE"
    cd "$SITE_PATH" && php "$SCRIPTS_PATH/create_site_backup.php" >> "$LOG_FILE" 2>&1
    
    if [ $? -eq 0 ]; then
        echo "$(date): 完整备份成功" >> "$LOG_FILE"
    else
        echo "$(date): 完整备份失败" >> "$LOG_FILE"
    fi
fi

# 每日清理旧备份文件
echo "$(date): 清理旧备份文件" >> "$LOG_FILE"
cd "$SITE_PATH" && php "$SCRIPTS_PATH/cleanup_backups.php" >> "$LOG_FILE" 2>&1

if [ $? -eq 0 ]; then
    echo "$(date): 清理任务完成" >> "$LOG_FILE"
else
    echo "$(date): 清理任务失败" >> "$LOG_FILE"
fi

# 记录结束时间
echo "$(date): 备份任务执行完成" >> "$LOG_FILE"
echo "----------------------------------------" >> "$LOG_FILE"