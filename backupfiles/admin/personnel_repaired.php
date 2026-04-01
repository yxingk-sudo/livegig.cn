<?php
// 简化版本 - 仅用于测试editPerson函数
session_start();

// 调试模式
$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';

if (!$debug_mode && !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>人员管理 - 修复版本</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>人员管理 - 修复版本</h1>
        
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>姓名</th>
                        <th>邮箱</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td>张三</td>
                        <td>zhangsan@example.com</td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="editPerson({id:1,name:'张三',email:'zhangsan@example.com',phone:'13800138000',id_card:'110101199001011234',gender:'M',company_id:1,project_id:1,department_ids:'1,2'})">
                                <i class="bi bi-pencil"></i> 编辑
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="deletePerson(1)">
                                <i class="bi bi-trash"></i> 删除
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td>2</td>
                        <td>李四</td>
                        <td>lisi@example.com</td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="editPerson({id:2,name:'李四',email:'lisi@example.com',phone:'13900139000',id_card:'110101199002022345',gender:'F',company_id:2,project_id:2,department_ids:'3,4'})">
                                <i class="bi bi-pencil"></i> 编辑
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="deletePerson(2)">
                                <i class="bi bi-trash"></i> 删除
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 编辑模态框 -->
    <div class="modal fade" id="editPersonModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">编辑人员</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form>
                    <div class="modal-body">
                        <input type="hidden" id="edit_id">
                        <div class="mb-3">
                            <label class="form-label">姓名</label>
                            <input type="text" class="form-control" id="edit_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">邮箱</label>
                            <input type="email" class="form-control" id="edit_email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">电话</label>
                            <input type="tel" class="form-control" id="edit_phone" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">身份证</label>
                            <input type="text" class="form-control" id="edit_id_card" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">性别</label>
                            <select class="form-control" id="edit_gender" required>
                                <option value="M">男</option>
                                <option value="F">女</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">公司ID</label>
                            <input type="number" class="form-control" id="edit_company" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">项目ID</label>
                            <input type="number" class="form-control" id="edit_project" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">部门ID</label>
                            <input type="text" class="form-control" id="edit_department_ids" placeholder="用逗号分隔多个ID">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-primary" onclick="savePerson()">保存</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 编辑人员函数
        function editPerson(person) {
            console.log('编辑人员:', person);
            
            // 填充表单
            document.getElementById('edit_id').value = person.id;
            document.getElementById('edit_name').value = person.name;
            document.getElementById('edit_email').value = person.email;
            document.getElementById('edit_phone').value = person.phone;
            document.getElementById('edit_id_card').value = person.id_card;
            document.getElementById('edit_gender').value = person.gender;
            document.getElementById('edit_company').value = person.company_id;
            document.getElementById('edit_project').value = person.project_id;
            document.getElementById('edit_department_ids').value = person.department_ids;
            
            // 显示模态框
            const modal = new bootstrap.Modal(document.getElementById('editPersonModal'));
            modal.show();
        }

        // 删除人员函数
        function deletePerson(personId) {
            if (confirm('确定要删除这个人员吗？')) {
                console.log('删除人员ID:', personId);
                alert('模拟删除成功！');
            }
        }

        // 保存人员函数
        function savePerson() {
            const person = {
                id: document.getElementById('edit_id').value,
                name: document.getElementById('edit_name').value,
                email: document.getElementById('edit_email').value,
                phone: document.getElementById('edit_phone').value,
                id_card: document.getElementById('edit_id_card').value,
                gender: document.getElementById('edit_gender').value,
                company_id: document.getElementById('edit_company').value,
                project_id: document.getElementById('edit_project').value,
                department_ids: document.getElementById('edit_department_ids').value
            };
            
            console.log('保存人员:', person);
            alert('模拟保存成功！');
            
            // 关闭模态框
            const modal = bootstrap.Modal.getInstance(document.getElementById('editPersonModal'));
            modal.hide();
        }
    </script>
</body>
</html>