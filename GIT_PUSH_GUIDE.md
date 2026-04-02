# Git 批量推送使用指南

## 📋 目录
- [首次推送（完整流程）](#首次推送完整流程)
- [日常快速推送](#日常快速推送)
- [手动推送命令](#手动推送命令)
- [常见问题解决](#常见问题解决)

---

## 🚀 首次推送（完整流程）

### 前提条件
1. **GitHub Personal Access Token (PAT)**
   - 访问：https://github.com/settings/tokens
   - 生成新 token，勾选 `repo` 权限
   - 保存 token（只显示一次）

2. **确认仓库已创建**
   - 访问：https://github.com/new
   - 创建仓库：`livegig.cn`
   - 设为公开或私有均可

### 执行步骤

```bash
# 1. 进入项目目录
cd /www/wwwroot/livegig.cn

# 2. 运行批量推送脚本
./batch_push.sh
```

### 脚本会自动完成：
- ✅ 初始化 Git 仓库
- ✅ 配置远程仓库地址
- ✅ 创建 .gitignore 文件
- ✅ 添加所有文件到暂存区
- ✅ 提交更改
- ✅ 推送到 GitHub

### 交互提示：
```
请输入提交消息 (默认：Initial commit): [输入自定义消息或直接回车]
Username for 'https://github.com': [输入 GitHub 用户名]
Password: [输入 Personal Access Token]
```

---

## ⚡ 日常快速推送

### 使用方法

```bash
# 使用默认提交消息
./quick_push.sh

# 使用自定义提交消息
./quick_push.sh "修复了登录页面的 bug"
```

### 适用场景：
- 代码修改后的日常提交
- 小功能开发完成
- Bug 修复后推送

---

## 📝 手动推送命令

### 完整流程（推荐新手）

```bash
# 1. 初始化（仅第一次）
git init

# 2. 添加远程仓库（仅第一次）
git remote add origin https://github.com/zhudong2024/livegig.cn.git

# 3. 添加文件
git add -A

# 4. 查看状态
git status

# 5. 提交
git commit -m "提交说明"

# 6. 推送
git push -u origin main
```

### 快捷方式（老手）

```bash
# 一键推送
git add -A && git commit -m "提交说明" && git push

# 仅推送特定文件
git add path/to/file.php
git commit -m "提交说明"
git push

# 查看提交历史
git log --oneline -10
```

---

## 🔧 常见问题解决

### 1. 认证失败

**错误信息：**
```
remote: Support for password authentication was removed on August 13, 2021.
```

**解决方案：**
- 必须使用 Personal Access Token (PAT) 代替密码
- 生成 PAT：GitHub → Settings → Developer settings → Personal access tokens
- Token 需要 `repo` 权限

**使用 Token：**
```bash
# 方法 1: 推送时输入 token
Username: zhudong2024
Password: github_pat_xxxxxxxxxxxx

# 方法 2: 在 URL 中包含 token（不推荐，仅测试用）
git remote set-url origin https://zhudong2024:TOKEN@github.com/zhudong2024/livegig.cn.git
```

### 2. 仓库不存在

**错误信息：**
```
remote: Not Found
fatal: repository 'https://github.com/zhudong2024/livegig.cn.git' not found
```

**解决方案：**
1. 访问 https://github.com/new
2. 创建仓库名：`livegig.cn`
3. 不要初始化 README（我们会推送现有代码）
4. 点击 "Create repository"

### 3. 推送被拒绝

**错误信息：**
```
! [rejected]        main -> main (fetch first)
```

**解决方案：**
```bash
# 先拉取远程更改
git pull origin main

# 如果有冲突，解决冲突后
git add -A
git commit -m "解决合并冲突"
git push

# 或者强制推送（慎用！会覆盖远程）
git push -f origin main
```

### 4. 网络超时

**错误信息：**
```
fatal: The remote end hung up unexpectedly
```

**解决方案：**
```bash
# 增加 Git 缓冲区
git config --global http.postBuffer 524288000

# 使用 SSH 连接（更稳定）
git remote set-url origin git@github.com:zhudong2024/livegig.cn.git

# 生成 SSH Key（如果没有）
ssh-keygen -t ed25519 -C "your_email@example.com"
cat ~/.ssh/id_ed25519.pub
# 复制输出到 GitHub → Settings → SSH and GPG keys → New SSH key
```

### 5. 忽略敏感文件

如果不小心提交了敏感文件：

```bash
# 1. 从 Git 历史中移除
git rm -r --cached config/
git commit -m "移除敏感配置文件"

# 2. 更新 .gitignore
echo "config/db_config.php" >> .gitignore

# 3. 重新推送
git push

# 4. 【重要】立即删除 GitHub 仓库中的敏感文件
# 5. 【重要】更改所有相关密码和密钥
```

---

## 📊 .gitignore 说明

项目已配置 .gitignore，自动忽略：
- ❌ 数据库配置文件（包含密码）
- ❌ 日志文件
- ❌ 备份文件
- ❌ IDE 配置
- ❌ 临时文件
- ❌ Composer vendor 目录

**注意：** 以下文件建议手动从 Git 中移除：
```bash
git rm -r --cached backup/
git rm -r --cached backupfiles/
git rm -r --cached backups/
git rm config/db_config.php
```

---

## 🎯 最佳实践

### 提交消息规范
```
feat: 新功能
fix: 修复 bug
docs: 文档更新
style: 格式调整
refactor: 重构代码
test: 测试相关
chore: 构建工具或依赖变动
```

### 示例：
```bash
./quick_push.sh "feat: 新增交通报表导出功能"
./quick_push.sh "fix: 修复餐费统计数字显示问题"
./quick_push.sh "refactor: 优化权限验证逻辑"
```

### 推送频率
- ✅ 每个功能完成后推送
- ✅ 每天下班前推送
- ✅ 重大修改前先推送当前稳定版本

---

## 📞 获取帮助

如遇问题，请检查：
1. GitHub 账号是否正常
2. Personal Access Token 是否有效
3. 网络连接是否正常
4. 仓库权限是否正确

**调试命令：**
```bash
# 检查远程仓库配置
git remote -v

# 检查当前分支
git branch -a

# 检查未提交的更改
git status

# 查看最近的提交
git log --oneline -5
```

---

**最后更新:** 2026-04-02
**维护者:** livegig.cn 开发团队
