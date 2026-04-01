<?php
// 酒店报告页面JavaScript代码 - 避免PHP解析器错误解析对象属性
// 使用heredoc语法输出JavaScript代码

echo <<<'JS'
<script>
// 筛选功能重构 - 模块化实现
document.addEventListener('DOMContentLoaded', function() {
    // 筛选管理器模块
    const filterManager = {
        // 存储当前筛选条件
        currentFilters: {
            project_id: '',
            status: '',
            check_in_date: '',
            check_out_date: ''
        },
        
        // 初始化筛选功能
        init: function() {
            // 从localStorage加载上次的筛选条件
            this.loadFiltersFromStorage();
            
            // 设置表单元素事件监听
            this.setupEventListeners();
            
            // 应用初始筛选条件
            this.applyFilters();
        },
        
        // 设置表单元素事件监听
        setupEventListeners: function() {
            // 筛选按钮点击事件
            document.getElementById('filterButton').addEventListener('click', () => {
                this.collectFilters();
                this.saveFiltersToStorage();
                this.applyFilters();
            });
            
            // 重置按钮点击事件
            document.getElementById('resetButton').addEventListener('click', () => {
                this.resetFilters();
            });
            
            // 单个筛选条件变化时实时应用（可选功能）
            const filterElements = ['project_id', 'status', 'check_in_date', 'check_out_date'];
            filterElements.forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.addEventListener('change', () => {
                        // 收集变化后的筛选条件
                        this.collectFilters();
                        // 延迟应用，避免频繁请求
                        clearTimeout(this.debounceTimer);
                        this.debounceTimer = setTimeout(() => {
                            this.saveFiltersToStorage();
                            this.applyFilters();
                        }, 500);
                    });
                }
            });
        },
        
        // 收集筛选条件
        collectFilters: function() {
            this.currentFilters = {
                project_id: document.getElementById('project_id').value,
                status: document.getElementById('status').value,
                check_in_date: document.getElementById('check_in_date').value,
                check_out_date: document.getElementById('check_out_date').value
            };
        },
        
        // 从localStorage加载筛选条件
        loadFiltersFromStorage: function() {
            try {
                const savedFilters = localStorage.getItem('hotelReportFilters');
                if (savedFilters) {
                    const parsed = JSON.parse(savedFilters);
                    this.currentFilters = { ...this.currentFilters, ...parsed };
                    
                    // 应用到表单元素
                    for (const [key, value] of Object.entries(this.currentFilters)) {
                        const element = document.getElementById(key);
                        if (element) {
                            element.value = value;
                        }
                    }
                }
            } catch (e) {
                console.error('加载筛选条件失败:', e);
            }
        },
        
        // 保存筛选条件到localStorage
        saveFiltersToStorage: function() {
            try {
                localStorage.setItem('hotelReportFilters', JSON.stringify(this.currentFilters));
            } catch (e) {
                console.error('保存筛选条件失败:', e);
            }
        },
        
        // 重置筛选条件
        resetFilters: function() {
            this.currentFilters = {
                project_id: '',
                status: '',
                check_in_date: '',
                check_out_date: ''
            };
            
            // 清空表单元素
            document.getElementById('project_id').value = '';
            document.getElementById('status').value = '';
            document.getElementById('check_in_date').value = '';
            document.getElementById('check_out_date').value = '';
            
            // 清除localStorage中的筛选条件
            localStorage.removeItem('hotelReportFilters');
            
            // 应用重置后的筛选条件
            this.applyFilters();
        },
        
        // 应用筛选条件（AJAX请求）
        applyFilters: function() {
            // 显示加载状态
            this.showLoadingState(true);
            
            // 构建查询参数
            const queryParams = new URLSearchParams(this.currentFilters);
            
            // 使用Fetch API发送AJAX请求
            fetch('hotel_reports.php?ajax=1&' + queryParams)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('网络响应错误');
                    }
                    return response.json();
                })
                .then(data => {
                    // 更新表格内容
                    const resultsContainer = document.getElementById('reportsContainer');
                    if (resultsContainer && data.success) {
                        resultsContainer.innerHTML = this.renderReports(data.reports, data.stats);
                        // 重新初始化表格相关功能
                        this.initTableFeatures();
                    } else {
                        this.showErrorMessage('数据加载失败');
                    }
                })
                .catch(error => {
                    console.error('筛选请求失败:', error);
                    this.showErrorMessage('筛选请求失败，请重试');
                })
                .finally(() => {
                    // 隐藏加载状态
                    this.showLoadingState(false);
                });
        },
        
        // 渲染报告列表
        renderReports: function(reports, stats) {
            // 状态映射
            const statusMap = {
                'pending': { label: '待确认', class: 'warning' },
                'confirmed': { label: '已确认', class: 'success' },
                'cancelled': { label: '已取消', class: 'danger' }
            };
            
            // 构建表格HTML
            let html = '<div class="card">';
            html += '<div class="card-header"><h5 class="mb-0">报酒店记录列表</h5></div>';
            html += '<div class="card-body">';
            
            if (!reports || reports.length === 0) {
                html += '<div class="text-center py-4">';
                html += '<i class="bi bi-inbox display-1 text-muted"></i>';
                html += '<h5 class="text-muted">暂无报酒店记录</h5>';
                html += '<p class="text-muted">请先添加报酒店记录</p>';
                html += '</div>';
            } else {
                html += '<div class="table-responsive">';
                html += '<table class="table table-striped table-hover">';
                html += '<thead class="table-dark">';
                html += '<tr>';
                html += '<th width="30"><input type="checkbox" id="selectAll"></th>';
                html += '<th>项目名称</th>';
                html += '<th>人员姓名</th>';
                html += '<th>酒店名称</th>';
                html += '<th>房型</th>';
                html += '<th>入住日期</th>';
                html += '<th>退房日期</th>';
                html += '<th>房间数</th>';
                html += '<th>共享房间</th>';
                html += '<th>特殊要求</th>';
                html += '<th>状态</th>';
                html += '<th>操作</th>';
                html += '</tr>';
                html += '</thead>';
                html += '<tbody>';
                
                // 渲染数据行
                reports.forEach(report => {
                    // 房间类型和共享房间信息处理
                    let badgeClass = '';
                    let badgeText = '';
                    
                    if (report.room_type === '双床房') {
                        badgeClass = 'bg-success';
                        badgeText = '双床房';
                    } else if (report.room_type === '双人房') {
                        badgeClass = 'bg-primary';
                        badgeText = '双人房';
                    } else {
                        badgeClass = 'bg-secondary';
                        badgeText = report.room_type;
                    }
                    
                    // 酒店名称中英文处理
                    let hotelNameHtml = report.hotel_name;
                    if (hotelNameHtml && hotelNameHtml.indexOf(' - ') !== -1) {
                        const [cn, en] = hotelNameHtml.split(' - ', 2);
                        hotelNameHtml = `${cn}<br><small class="text-muted">${en}</small>`;
                    }
                    
                    // 共享房间信息显示
                    let sharedRoomHtml = `<span class="badge ${badgeClass}">${badgeText}</span>`;
                    if (report.shared_room_info && report.shared_room_info !== '') {
                        sharedRoomHtml += `<br><small class="text-muted" title="共享房间信息">${report.shared_room_info}</small>`;
                    }
                    
                    // 添加行
                    html += '<tr>';
                    html += `<td><input type="checkbox" name="ids[]" value="${report.record_ids}" class="row-checkbox"></td>`;
                    html += `<td>${report.project_name || '-'}</td>`;
                    html += `<td>${report.personnel_name || '-'}</td>`;
                    html += `<td>${hotelNameHtml}</td>`;
                    html += `<td>${report.room_type || '-'}</td>`;
                    html += `<td>${report.check_in_date || '-'}</td>`;
                    html += `<td>${report.check_out_date || '-'}</td>`;
                    html += `<td>${report.room_count || 0}</td>`;
                    html += `<td>${sharedRoomHtml}</td>`;
                    html += `<td>${report.special_requirements || '-'}</td>`;
                    
                    // 状态下拉框
                    const status = statusMap[report.status] || { label: report.status, class: '' };
                    html += '<td>';
                    html += `<select class="form-select form-select-sm status-update" data-ids="${report.record_ids}">`;
                    html += `<option value="pending" ${report.status === 'pending' ? 'selected' : ''}>待确认</option>`;
                    html += `<option value="confirmed" ${report.status === 'confirmed' ? 'selected' : ''}>已确认</option>`;
                    html += `<option value="cancelled" ${report.status === 'cancelled' ? 'selected' : ''}>已取消</option>`;
                    html += '</select>';
                    html += '</td>';
                    
                    // 操作按钮
                    html += '<td>';
                    html += `<a href="hotel_reports.php?action=edit&ids=${report.record_ids}" class="btn btn-sm btn-warning">编辑</a>`;
                    html += `<a href="hotel_reports.php?action=delete&ids=${report.record_ids}" `;
                    html += 'class="btn btn-sm btn-danger ml-1" ';
                    html += 'onclick="return confirm(\'确定要删除这组共享房间记录吗？\')">删除</a>';
                    html += '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody>';
                html += '</table>';
                html += '</div>';
                
                // 批量操作按钮
                html += '<div class="mt-3">';
                html += '<button type="button" class="btn btn-success me-2" onclick="performBatchAction(\'batch_confirm\')">';
                html += '<i class="bi bi-check2-square"></i> 批量确认';
                html += '</button>';
                html += '<button type="button" class="btn btn-danger" onclick="performBatchAction(\'batch_delete\')">';
                html += '<i class="bi bi-trash"></i> 批量删除';
                html += '</button>';
                html += '</div>';
            }
            
            html += '</div>';
            html += '</div>';
            
            return html;
        },
        
        // 显示/隐藏加载状态
        showLoadingState: function(show) {
            const loadingOverlay = document.getElementById('loadingOverlay');
            if (loadingOverlay) {
                loadingOverlay.style.display = show ? 'flex' : 'none';
            }
        },
        
        // 显示错误消息
        showErrorMessage: function(message) {
            const errorContainer = document.getElementById('errorContainer');
            if (errorContainer) {
                errorContainer.textContent = message;
                errorContainer.style.display = 'block';
                // 3秒后自动隐藏
                setTimeout(() => {
                    errorContainer.style.display = 'none';
                }, 3000);
            }
        },
        
        // 初始化表格功能（复选框、状态更新等）
        initTableFeatures: function() {
            // 全选复选框
            const selectAllCheckbox = document.getElementById('selectAll');
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('click', function() {
                    const checkboxes = document.querySelectorAll('.row-checkbox');
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            }
            
            // 状态更新下拉框
            const statusSelects = document.querySelectorAll('.status-update');
            statusSelects.forEach(select => {
                select.addEventListener('change', function() {
                    // 获取选择的状态
                    const status = this.value;
                    // 获取记录ID
                    const id = this.dataset.id;
                    
                    // 调用更新状态函数
                    updateStatus(id, status, this);
                });
            });
        },
        
        // 防抖定时器
        debounceTimer: null
    };
    
    // 保存到全局，方便外部调用
    window.filterManager = filterManager;
    
    // 初始化筛选管理器
    filterManager.init();
});

// 更新状态函数（AJAX实现）
function updateStatus(id, status, selectElement) {
    // 保存原始值，以便在出错时恢复
    selectElement.dataset.originalValue = selectElement.value;
    
    // 发送AJAX请求更新状态
    fetch('hotel_reports.php?ajax=1', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=update_status&id=${id}&status=${status}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // 状态更新成功，可以添加成功提示
            console.log('状态更新成功:', data.message);
        } else {
            alert('更新失败：' + (data.message || '未知错误'));
            // 恢复原来的选择
            selectElement.value = selectElement.dataset.originalValue;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('更新失败：网络错误');
        // 恢复原来的选择
        selectElement.value = selectElement.dataset.originalValue;
    });
}

// 批量操作函数
function performBatchAction(action) {
    // 获取选中的ID
    const selectedCheckboxes = document.querySelectorAll('.row-checkbox:checked');
    if (selectedCheckboxes.length === 0) {
        alert('请至少选择一条记录');
        return;
    }
    
    // 构建ID数组
    const ids = Array.from(selectedCheckboxes).map(checkbox => checkbox.value);
    
    // 确认操作
    if (!confirm(`确定要对选中的 ${ids.length} 条记录执行${action === 'batch_confirm' ? '批量确认' : '批量删除'}操作吗？`)) {
        return;
    }
    
    // 发送AJAX请求执行批量操作
    fetch('hotel_reports.php?ajax=1', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=${action}&ids=${ids.join(',')}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message || '操作成功');
            // 刷新页面或重新加载数据
            window.location.reload();
        } else {
            alert('操作失败：' + (data.message || '未知错误'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('操作失败：网络错误');
    });
}
</script>
JS;
?>