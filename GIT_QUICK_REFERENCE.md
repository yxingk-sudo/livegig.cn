# Git 批量推送 - 快速参考卡

## 🚀 三种推送方式

### 1️⃣ 一键自动推送（推荐）
```bash
./auto_push.sh
```
**特点：**
- ✅ 全自动流程，包含所有步骤
- ✅ 自动生成带日期的提交消息
- ✅ 详细的进度提示和错误处理
- ✅ 适合首次推送或完整更新

---

### 2️⃣ 交互式批量推送
```bash
./batch_push.sh
```
**特点：**
- ✅ 逐步引导，可自定义提交消息
- ✅ 适合需要详细说明的提交
- ✅ 完整的检查和配置

---

### 3️⃣ 快速日常推送
```bash
./quick_push.sh "修复了登录 bug"
```
**特点：**
- ✅ 最简洁，适合日常开发
- ✅ 支持自定义提交消息
- ✅ 快速添加、提交、推送

---

## 📋 使用前准备

### ① 创建 GitHub 仓库
```
访问：https://github.com/new
仓库名：livegig.cn
可见性：公开或私有
⚠️ 不要勾选"Initialize with README"
```

### ② 获取 Personal Access Token
```
访问：https://github.com/settings/tokens
点击：Generate new token (classic)
备注：livegig.cn deployment
权限：✅ repo (全选)
生成后：复制并保存 token（只显示一次）
```

---

## 🎯 典型使用场景

### 场景 1：首次完整推送
```bash
cd /www/wwwroot/livegig.cn
./auto_push.sh
# 按提示输入 GitHub 用户名和 Token
```

### 场景 2：每天下班前推送
```bash
./quick_push.sh "feat: 完成餐费管理模块优化"
```

### 场景 3：功能开发完成推送
```bash
./quick_push.sh "feat: 交通报表新增混合交通安排功能"
```

### 场景 4：紧急 Bug 修复
```bash
./quick_push.sh "fix: 修复双床房统计错误"
```

---

## 🔧 常用 Git 命令速查

### 查看状态
```bash
git status          # 查看文件变更
git log --oneline   # 查看提交历史
git remote -v       # 查看远程仓库
git branch -a       # 查看分支
```

### 手动操作
```bash
git add -A                 # 添加所有更改
git commit -m "消息"        # 提交更改
git push                   # 推送到远程
git pull origin main       # 拉取远程更新
```

### 撤销操作
```bash
git reset HEAD~1          # 撤销最后一次提交（保留更改）
git reset --hard HEAD~1   # 完全删除最后一次提交
git revert HEAD           # 通过新提交撤销上一次
```

---

## ⚠️ 注意事项

### 敏感文件保护
以下文件已被 .gitignore 忽略，不会被推送：
- ❌ config/db_config.php（数据库配置）
- ❌ *.log（日志文件）
- ❌ backup/（备份目录）
- ❌ .user.ini, .htaccess

### 提交消息规范
```
feat: 新功能
fix: Bug 修复
docs: 文档更新
style: 格式调整
refactor: 重构
test: 测试
chore: 杂项
```

### 推送频率建议
- ✅ 每个功能完成后立即推送
- ✅ 每天至少推送一次
- ✅ 重大修改前先推送稳定版本
- ❌ 避免一周以上不推送

---

## 🆘 故障排除速查

| 问题 | 解决方案 |
|------|----------|
| 认证失败 | 使用 PAT 代替密码 |
| 仓库不存在 | 先在 GitHub 创建仓库 |
| 推送被拒绝 | `git pull` 后再推送 |
| 网络超时 | 使用 SSH 连接或增加 buffer |
| 冲突 | 手动解决冲突后重新提交 |

---

## 📞 获取帮助

详细文档：[GIT_PUSH_GUIDE.md](./GIT_PUSH_GUIDE.md)

遇到问题时的诊断信息：
```bash
# 检查配置
git remote -v
git branch -a
git log --oneline -5

# 检查更改
git status
git diff --stat
```

---

**快速入门：**
1. 创建 GitHub 仓库
2. 获取 Personal Access Token
3. 运行 `./auto_push.sh`
4. 按提示输入凭据
5. 完成！✅

**最后更新:** 2026-04-02
