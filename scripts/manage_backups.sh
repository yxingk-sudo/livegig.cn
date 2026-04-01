#!/bin/bash

# 数据库备份管理脚本

BACKUP_DIR="/www/wwwroot/livegig.cn/backups"

# 显示帮助信息
show_help() {
    echo "数据库备份管理工具"
    echo "使用方法: $0 [选项]"
    echo "选项:"
    echo "  list, l     列出所有备份文件"
    echo "  size, s     显示备份目录大小"
    echo "  count, c    显示备份文件数量"
    echo "  clean, cl   清理7天前的旧备份文件"
    echo "  help, h     显示此帮助信息"
}

# 列出所有备份文件
list_backups() {
    echo "备份文件列表:"
    ls -lh $BACKUP_DIR/*.sql.gz 2>/dev/null
    if [ $? -ne 0 ]; then
        echo "没有找到备份文件"
    fi
}

# 显示备份目录大小
show_size() {
    echo "备份目录大小:"
    du -sh $BACKUP_DIR
}

# 显示备份文件数量
count_backups() {
    local count=$(ls $BACKUP_DIR/*.sql.gz 2>/dev/null | wc -l)
    echo "备份文件数量: $count"
}

# 清理旧备份文件
clean_old_backups() {
    echo "清理7天前的旧备份文件..."
    local count_before=$(ls $BACKUP_DIR/*.sql.gz 2>/dev/null | wc -l)
    find $BACKUP_DIR -name "*.sql.gz" -mtime +7 -delete
    local count_after=$(ls $BACKUP_DIR/*.sql.gz 2>/dev/null | wc -l)
    local deleted=$((count_before - count_after))
    echo "已删除 $deleted 个旧备份文件"
    echo "剩余备份文件数量: $count_after"
}

# 主程序
case "${1:-help}" in
    list|l)
        list_backups
        ;;
    size|s)
        show_size
        ;;
    count|c)
        count_backups
        ;;
    clean|cl)
        clean_old_backups
        ;;
    help|h|*)
        show_help
        ;;
esac