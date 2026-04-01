// 主JavaScript文件 - 企业项目管理系统
(function() {
    'use strict';

    // 全局变量
    const API_BASE = '/api/';
    
    // 工具函数
    const Utils = {
        // 格式化日期
        formatDate: function(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString('zh-CN');
        },

        // 显示加载状态
        showLoading: function(elementId) {
            const element = document.getElementById(elementId);
            if (element) {
                element.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">加载中...</span></div></div>';
            }
        },

        // 显示错误信息
        showError: function(elementId, message) {
            const element = document.getElementById(elementId);
            if (element) {
                element.innerHTML = `<div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i> ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>`;
            }
        },

        // 显示成功信息
        showSuccess: function(elementId, message) {
            const element = document.getElementById(elementId);
            if (element) {
                element.innerHTML = `<div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill"></i> ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>`;
            }
        },

        // 确认对话框
        confirm: function(message, callback) {
            if (confirm(message)) {
                callback();
            }
        },

        // AJAX请求
        ajax: function(options) {
            const defaultOptions = {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin'
            };

            const config = Object.assign({}, defaultOptions, options);
            
            return fetch(config.url, config)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .catch(error => {
                    console.error('API请求失败:', error);
                    throw error;
                });
        }
    };

    // 表单处理
    const FormHandler = {
        // 序列化表单数据
        serialize: function(form) {
            const formData = new FormData(form);
            const data = {};
            formData.forEach((value, key) => {
                data[key] = value;
            });
            return data;
        },

        // 重置表单
        reset: function(formId) {
            const form = document.getElementById(formId);
            if (form) {
                form.reset();
                form.classList.remove('was-validated');
            }
        },

        // 验证表单
        validate: function(form) {
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                return false;
            }
            return true;
        }
    };

    // 模态框管理
    const ModalManager = {
        // 显示模态框
        show: function(modalId) {
            const modal = new bootstrap.Modal(document.getElementById(modalId));
            modal.show();
            return modal;
        },

        // 隐藏模态框
        hide: function(modalId) {
            const modal = bootstrap.Modal.getInstance(document.getElementById(modalId));
            if (modal) {
                modal.hide();
            }
        },

        // 重置模态框内容
        reset: function(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                const form = modal.querySelector('form');
                if (form) {
                    FormHandler.reset(form.id);
                }
            }
        }
    };

    // 表格操作
    const TableManager = {
        // 更新表格行
        updateRow: function(tableId, data, rowId) {
            const table = document.getElementById(tableId);
            if (!table) return;

            const row = table.querySelector(`tr[data-id="${rowId}"]`);
            if (row) {
                // 更新行内容
                const cells = row.querySelectorAll('td');
                Object.keys(data).forEach((key, index) => {
                    if (cells[index]) {
                        cells[index].textContent = data[key];
                    }
                });
            }
        },

        // 添加表格行
        addRow: function(tableId, data, columns) {
            const table = document.getElementById(tableId);
            if (!table) return;

            const tbody = table.querySelector('tbody');
            if (!tbody) return;

            const row = document.createElement('tr');
            row.setAttribute('data-id', data.id);

            columns.forEach(column => {
                const td = document.createElement('td');
                td.textContent = data[column] || '-';
                row.appendChild(td);
            });

            tbody.appendChild(row);
        },

        // 删除表格行
        deleteRow: function(tableId, rowId) {
            const table = document.getElementById(tableId);
            if (!table) return;

            const row = table.querySelector(`tr[data-id="${rowId}"]`);
            if (row) {
                row.remove();
            }
        }
    };

    // 初始化工具提示
    function initTooltips() {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }

    // 初始化弹出框
    function initPopovers() {
        const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
        popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl);
        });
    }

    // 页面加载完成后初始化
    document.addEventListener('DOMContentLoaded', function() {
        // 初始化所有组件
        initTooltips();
        initPopovers();

        // 添加全局工具到window对象
        window.Utils = Utils;
        window.FormHandler = FormHandler;
        window.ModalManager = ModalManager;
        window.TableManager = TableManager;

        console.log('企业项目管理系统已加载');
    });

    // 导出到全局作用域
    window.ProjectSystem = {
        Utils,
        FormHandler,
        ModalManager,
        TableManager
    };

})();