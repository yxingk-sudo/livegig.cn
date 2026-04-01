#!/bin/bash

# 数据库备份脚本
# 设置变量
DB_CONFIG="/www/wwwroot/livegig.cn/scripts/mysql.cnf"
DB_NAME="team_reception"
BACKUP_DIR="/www/wwwroot/livegig.cn/backups"
DATE=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="$BACKUP_DIR/${DB_NAME}_backup_$DATE.sql"
COMPRESSED_FILE="$BACKUP_FILE.gz"

# 创建备份目录（如果不存在）
mkdir -p $BACKUP_DIR

# 执行数据库备份
mysqldump --defaults-extra-file=$DB_CONFIG $DB_NAME > $BACKUP_FILE

# 检查备份是否成功
if [ $? -eq 0 ]; then
    # 压缩备份文件
    gzip $BACKUP_FILE
    
    # 删除7天前的备份文件
    find $BACKUP_DIR -name "${DB_NAME}_backup_*.sql.gz" -mtime +7 -delete
    
    echo "数据库备份成功: $COMPRESSED_FILE"
else
    echo "数据库备份失败"
    exit 1
fi