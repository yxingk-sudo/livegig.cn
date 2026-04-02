#!/bin/bash

# 快速推送脚本 - 用于日常提交

set -e

echo "======================================"
echo "Git 快速推送"
echo "======================================"
echo ""

# 颜色定义
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

# 检查参数
COMMIT_MSG=${1:-"Auto commit"}

# 添加所有更改
print_status "添加文件到暂存区..."
git add -A

# 显示状态
echo ""
print_status "当前更改:"
git status --short

# 提交
echo ""
print_status "提交更改: $COMMIT_MSG"
git commit -m "$COMMIT_MSG"

# 推送
print_status "推送到远程仓库..."
if git push; then
    echo ""
    echo -e "${GREEN}✓ 推送成功！${NC}"
else
    echo -e "${YELLOW}⚠ 推送失败，请检查网络连接或认证信息${NC}"
    exit 1
fi
