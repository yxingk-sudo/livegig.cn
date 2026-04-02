#!/bin/bash

# Git 批量推送脚本
# 用于将 livegig.cn 项目推送到 GitHub 仓库

set -e  # 遇到错误立即退出

echo "======================================"
echo "Git 批量推送脚本"
echo "======================================"
echo ""

# 配置
REMOTE_URL="https://github.com/zhudong2024/livegig.cn.git"
BRANCH_NAME="master"

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 函数：打印状态
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# 步骤 1: 检查 Git 是否已初始化
print_status "步骤 1: 检查 Git 仓库状态..."
if [ ! -d ".git" ]; then
    print_status "初始化 Git 仓库..."
    git init
else
    print_status "Git 仓库已存在"
fi

# 步骤 2: 配置远程仓库
print_status "步骤 2: 配置远程仓库..."
if git remote | grep -q "^origin$"; then
    print_warning "远程仓库已存在，更新 URL..."
    git remote set-url origin $REMOTE_URL
else
    print_status "添加远程仓库..."
    git remote add origin $REMOTE_URL
fi

# 步骤 3: 检查 .gitignore
print_status "步骤 3: 检查 .gitignore 文件..."
if [ ! -f ".gitignore" ]; then
    print_status "创建 .gitignore 文件..."
    cat > .gitignore << EOF
# 日志文件
logs/
*.log

# 备份文件
backup/
backupfiles/
backups/
*.bak
*.tar.gz
*.zip

# 配置文件（包含敏感信息）
config/db_config.php
.user.ini
.htaccess

# 临时文件
tmp/
temp/
*.tmp
*.swp
*.swo
*~

# IDE 配置
.vscode/
.idea/
*.sublime-*

# 系统文件
.DS_Store
Thumbs.db

# Composer
vendor/
composer.lock

# Node modules (如果有)
node_modules/

# 测试文件
test_*.php
menu_test.php
EOF
    print_status ".gitignore 文件已创建"
else
    print_status ".gitignore 文件已存在"
fi

# 步骤 4: 添加文件到暂存区
print_status "步骤 4: 添加所有文件到暂存区..."
git add -A
echo ""
print_status "当前 Git 状态:"
git status --short

# 步骤 5: 提交更改
print_status "步骤 5: 提交更改..."
read -p "请输入提交消息 (默认：Initial commit): " COMMIT_MSG
COMMIT_MSG=${COMMIT_MSG:-"Initial commit"}
git commit -m "$COMMIT_MSG"

# 步骤 6: 推送到 GitHub
print_status "步骤 6: 推送到 GitHub..."
echo ""
print_warning "注意：需要输入 GitHub 用户名和密码 (或 Personal Access Token)"
echo ""

# 尝试推送
if git push -u origin $BRANCH_NAME; then
    echo ""
    print_status "======================================"
    echo -e "${GREEN}✓ 推送成功！${NC}"
    print_status "======================================"
    echo ""
    echo "仓库地址：https://github.com/zhudong2024/livegig.cn"
    echo "分支：$BRANCH_NAME"
else
    echo ""
    print_error "======================================"
    echo -e "${RED}✗ 推送失败！${NC}"
    print_error "======================================"
    echo ""
    print_warning "可能的原因:"
    echo "1. 仓库不存在 - 请先在 GitHub 上创建仓库"
    echo "2. 认证失败 - 请检查用户名密码或 PAT"
    echo "3. 网络问题 - 请检查网络连接"
    echo ""
    print_warning "建议:"
    echo "- 使用 Personal Access Token 代替密码"
    echo "- 在 GitHub  Settings -> Developer settings -> Personal access tokens 生成 token"
    echo "- Token 需要 repo 权限"
    exit 1
fi

# 步骤 7: 验证推送
print_status "步骤 7: 验证推送结果..."
git log --oneline -5

echo ""
print_status "======================================"
print_status "批量推送完成！"
print_status "======================================"
