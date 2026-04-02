#!/bin/bash

# 一键推送脚本 - 无需交互，自动完成

set -e

echo "======================================"
echo "Git 一键批量推送"
echo "======================================"
echo ""

# 配置
REMOTE_URL="https://github.com/zhudong2024/livegig.cn.git"
BRANCH_NAME="main"
COMMIT_MSG="feat: 批量提交项目更新 - $(date +%Y%m%d)"

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_step() {
    echo -e "${BLUE}════════════════════════════════════${NC}"
    echo -e "${BLUE}步骤 $1${NC}: $2"
    echo -e "${BLUE}════════════════════════════════════${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

# 步骤 1: 初始化 Git
print_step "1" "检查 Git 仓库"
if [ ! -d ".git" ]; then
    git init
    print_success "Git 仓库已初始化"
else
    print_success "Git 仓库已存在"
fi

# 步骤 2: 配置远程仓库
print_step "2" "配置远程仓库"
if git remote | grep -q "^origin$"; then
    git remote set-url origin $REMOTE_URL
    print_warning "远程仓库 URL 已更新"
else
    git remote add origin $REMOTE_URL
    print_success "远程仓库已添加"
fi

# 步骤 3: 创建 .gitignore
print_step "3" "配置 .gitignore"
if [ ! -f ".gitignore" ]; then
    cat > .gitignore << 'EOF'
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

# 配置文件
config/db_config.php
.user.ini
.htaccess

# 临时文件
tmp/
temp/
*.tmp
*.swp
*~

# IDE
.vscode/
.idea/

# 系统文件
.DS_Store
Thumbs.db

# 测试文件
test_*.php
menu_test.php
EOF
    print_success ".gitignore 已创建"
else
    print_success ".gitignore 已存在"
fi

# 步骤 4: 添加文件
print_step "4" "添加文件到暂存区"
git add -A
CHANGED_FILES=$(git status --short | wc -l)
print_success "已添加 $CHANGED_FILES 个文件更改"

# 步骤 5: 提交
print_step "5" "提交更改"
git commit -m "$COMMIT_MSG"
print_success "提交完成：$COMMIT_MSG"

# 步骤 6: 显示摘要
echo ""
print_step "6" "提交摘要"
echo "最近 5 次提交:"
git log --oneline -5

echo ""
echo "准备推送的文件变更:"
git diff --stat HEAD~1 HEAD 2>/dev/null || echo "(首次提交)"

# 步骤 7: 推送
print_step "7" "推送到 GitHub"
echo ""
print_warning "提示："
echo "- 输入 GitHub 用户名后回车"
echo "- 输入 Personal Access Token 后回车（不显示）"
echo "- Token 获取：https://github.com/settings/tokens"
echo ""

if git push -u origin $BRANCH_NAME; then
    echo ""
    print_success "======================================"
    print_success "🎉 推送成功！"
    print_success "======================================"
    echo ""
    echo "📦 仓库地址：https://github.com/zhudong2024/livegig.cn"
    echo "🌿 分支名称：$BRANCH_NAME"
    echo "📝 提交信息：$COMMIT_MSG"
    echo ""
    print_success "查看提交历史:"
    echo "   git log --oneline -10"
    echo ""
    print_success "下次快速推送:"
    echo "   ./quick_push.sh \"你的提交消息\""
else
    echo ""
    print_error "======================================"
    print_error "✗ 推送失败"
    print_error "======================================"
    echo ""
    print_warning "可能的原因:"
    echo "  1. 仓库不存在 → https://github.com/new 创建 livegig.cn"
    echo "  2. 认证失败 → 使用 Personal Access Token 代替密码"
    echo "  3. 网络问题 → 检查网络连接"
    echo ""
    print_warning "解决方案:"
    echo "  1. 生成 Token: https://github.com/settings/tokens"
    echo "  2. 勾选 repo 权限"
    echo "  3. 复制 Token 并在推送时粘贴"
    exit 1
fi
