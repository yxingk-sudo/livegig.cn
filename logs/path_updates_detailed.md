# 路径更新清单

## 需要更新的文件引用

1. **admin/edit_transportation.php**
   - 原路径: 'get_all_personnel.php'
   - 新路径: 'api/get_all_personnel.php'

2. **admin/personnel_statistics_fixed.php**
   - 原路径: 'get_person_details_enhanced.php'
   - 新路径: 'api/get_person_details_enhanced.php'

3. **admin/batch_add_personnel_step2.php**
   - 原路径: 'api_get_projects.php'
   - 新路径: 'api/api_get_projects.php'
   - 原路径: 'api_get_departments.php'
   - 新路径: 'api/api_get_departments.php'

## 注意事项
- 所有 api_*.php 文件已经移动到 admin/api/ 目录
- 所有 get_*.php 文件已经移动到 admin/api/ 目录
- 需要更新的路径引用都是相对路径，相对于 admin/ 目录
