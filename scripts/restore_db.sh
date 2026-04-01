#!/bin/bash

# 数据库恢复脚本
# 使用方法: ./restore_db.sh <备份文件路径>

if [ $# -ne 1 ]; then
    echo "使用方法: $0 <备份文件路径>"
    echo "例如: $0 /www/wwwroot/livegig.cn/backups/team_reception_backup_20250919_180051.sql.gz"
    exit 1
fi

BACKUP_FILE=$1
DB_CONFIG="/www/wwwroot/livegig.cn/scripts/mysql.cnf"
DB_NAME="team_reception"

# 检查备份文件是否存在
if [ ! -f "$BACKUP_FILE" ]; then
    echo "错误: 备份文件 $BACKUP_FILE 不存在"
    exit 1
fi

# 确认恢复操作
echo "警告: 这将覆盖当前数据库中的所有数据!"
read -p "确定要继续恢复操作吗? (输入 'yes' 确认): " confirmation

if [ "$confirmation" != "yes" ]; then
    echo "恢复操作已取消"
    exit 0
fi

# 解压备份文件(如果是.gz格式)
if [[ $BACKUP_FILE == *.gz ]]; then
    TEMP_FILE="/tmp/$(basename ${BACKUP_FILE%.*})"
    echo "解压备份文件..."
    gunzip -c "$BACKUP_FILE" > "$TEMP_FILE"
    RESTORE_FILE="$TEMP_FILE"
else
    RESTORE_FILE="$BACKUP_FILE"
fi

# 执行数据库恢复
echo "正在恢复数据库..."
mysql --defaults-extra-file=$DB_CONFIG $DB_NAME < "$RESTORE_FILE"

# 检查恢复是否成功
if [ $? -eq 0 ]; then
    echo "数据库恢复成功"
else
    echo "数据库恢复失败"
    exit 1
fi

# 清理临时文件
if [ -n "$TEMP_FILE" ] && [ -f "$TEMP_FILE" ]; then
    rm "$TEMP_FILE"
fi