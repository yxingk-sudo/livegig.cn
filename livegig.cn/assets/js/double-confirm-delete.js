/**
 * 双重确认删除机制
 * 提供统一的删除确认对话框，确保用户充分意识到删除操作的不可逆性
 */

// 全局配置
const DoubleConfirmConfig = {
    // 第二层确认需要输入的文本
    confirmText: '确认删除',
    // 对话框样式配置
    dialogClass: 'double-confirm-dialog',
    // 是否启用双重确认（可用于调试）
    enabled: true
};

/**
 * 显示双重确认删除对话框
 * @param {string} itemName - 要删除的项名称
 * @param {string} itemType - 项类型（如"人员"、"项目"等）
 * @param {Function} onConfirm - 确认后的回调函数
 * @param {Function} onCancel - 取消后的回调函数（可选）
 */
function showDoubleConfirmDelete(itemName, itemType, onConfirm, onCancel) {
    if (!DoubleConfirmConfig.enabled) {
        onConfirm();
        return;
    }

    // 第一层确认
    const firstConfirmMessage = `确定要删除${itemType} "${itemName}" 吗？\n\n⚠️ 警告：此操作不可恢复，删除后数据将无法找回！`;
    
    if (!confirm(firstConfirmMessage)) {
        if (typeof onCancel === 'function') {
            onCancel();
        }
        return;
    }

    // 第二层确认 - 创建自定义模态框
    createSecondConfirmDialog(itemName, itemType, onConfirm, onCancel);
}

/**
 * 创建第二层确认对话框
 */
function createSecondConfirmDialog(itemName, itemType, onConfirm, onCancel) {
    // 移除已存在的对话框
    const existingDialog = document.getElementById('doubleConfirmModal');
    if (existingDialog) {
        existingDialog.remove();
    }

    // 创建模态框HTML
    const modalHtml = `
        <div class="modal fade" id="doubleConfirmModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-danger">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            最终确认删除
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <strong>⚠️ 危险操作警告</strong><br>
                            您正在尝试删除${itemType} "<strong>${escapeHtml(itemName)}</strong>"。
                            此操作将永久删除该记录及其相关数据，无法撤销！
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                请在下方输入框中输入 "<strong>${DoubleConfirmConfig.confirmText}</strong>" 以确认删除：
                            </label>
                            <input type="text" 
                                   class="form-control form-control-lg" 
                                   id="confirmDeleteInput" 
                                   placeholder="请输入：${DoubleConfirmConfig.confirmText}"
                                   autocomplete="off">
                            <div class="form-text text-danger">
                                输入正确的确认文本后，删除按钮才会启用
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i>取消
                        </button>
                        <button type="button" class="btn btn-danger" id="finalDeleteBtn" disabled>
                            <i class="bi bi-trash me-1"></i>确认删除
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;

    // 添加到页面
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    // 获取元素
    const modal = new bootstrap.Modal(document.getElementById('doubleConfirmModal'));
    const confirmInput = document.getElementById('confirmDeleteInput');
    const finalDeleteBtn = document.getElementById('finalDeleteBtn');

    // 输入验证
    confirmInput.addEventListener('input', function() {
        const isValid = this.value.trim() === DoubleConfirmConfig.confirmText;
        finalDeleteBtn.disabled = !isValid;
        if (isValid) {
            finalDeleteBtn.classList.add('animate-pulse');
        } else {
            finalDeleteBtn.classList.remove('animate-pulse');
        }
    });

    // 确认删除按钮事件
    finalDeleteBtn.addEventListener('click', function() {
        if (confirmInput.value.trim() === DoubleConfirmConfig.confirmText) {
            modal.hide();
            // 延迟执行以确保模态框关闭动画完成
            setTimeout(() => {
                onConfirm();
                cleanupModal();
            }, 300);
        }
    });

    // 取消/关闭事件
    document.getElementById('doubleConfirmModal').addEventListener('hidden.bs.modal', function() {
        if (typeof onCancel === 'function') {
            onCancel();
        }
        cleanupModal();
    });

    // 显示模态框
    modal.show();
    
    // 聚焦输入框
    setTimeout(() => confirmInput.focus(), 500);
}

/**
 * 清理模态框DOM
 */
function cleanupModal() {
    const modal = document.getElementById('doubleConfirmModal');
    if (modal) {
        modal.remove();
    }
    // 移除modal-open类和backdrop
    document.body.classList.remove('modal-open');
    const backdrop = document.querySelector('.modal-backdrop');
    if (backdrop) {
        backdrop.remove();
    }
}

/**
 * HTML转义函数
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * 简化的双重确认（用于批量删除）
 * @param {number} count - 要删除的数量
 * @param {string} itemType - 项类型
 * @param {Function} onConfirm - 确认回调
 */
function showBatchDoubleConfirmDelete(count, itemType, onConfirm) {
    if (!DoubleConfirmConfig.enabled) {
        onConfirm();
        return;
    }

    // 第一层确认
    const firstMessage = `确定要批量删除 ${count} 条${itemType}记录吗？\n\n⚠️ 警告：此操作不可恢复！`;
    
    if (!confirm(firstMessage)) {
        return;
    }

    // 第二层确认 - 批量删除使用数字确认
    createBatchConfirmDialog(count, itemType, onConfirm);
}

/**
 * 创建批量删除确认对话框
 */
function createBatchConfirmDialog(count, itemType, onConfirm) {
    const modalId = 'batchConfirmModal';
    const existingDialog = document.getElementById(modalId);
    if (existingDialog) {
        existingDialog.remove();
    }

    const confirmNumber = Math.floor(Math.random() * 9000) + 1000; // 随机4位数

    const modalHtml = `
        <div class="modal fade" id="${modalId}" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-danger">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            批量删除最终确认
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger">
                            <strong>⚠️ 危险操作</strong><br>
                            您即将永久删除 <strong>${count}</strong> 条${itemType}记录！
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                请输入以下数字以确认删除：<br>
                                <span class="h3 text-danger font-monospace">${confirmNumber}</span>
                            </label>
                            <input type="text" 
                                   class="form-control form-control-lg text-center font-monospace" 
                                   id="batchConfirmInput" 
                                   placeholder="输入上方数字"
                                   maxlength="4"
                                   autocomplete="off">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i>取消
                        </button>
                        <button type="button" class="btn btn-danger" id="batchFinalDeleteBtn" disabled>
                            <i class="bi bi-trash me-1"></i>确认批量删除
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHtml);

    const modal = new bootstrap.Modal(document.getElementById(modalId));
    const confirmInput = document.getElementById('batchConfirmInput');
    const finalDeleteBtn = document.getElementById('batchFinalDeleteBtn');

    confirmInput.addEventListener('input', function() {
        finalDeleteBtn.disabled = this.value.trim() !== confirmNumber.toString();
    });

    finalDeleteBtn.addEventListener('click', function() {
        if (confirmInput.value.trim() === confirmNumber.toString()) {
            modal.hide();
            setTimeout(() => {
                onConfirm();
                document.getElementById(modalId)?.remove();
            }, 300);
        }
    });

    modal.show();
    setTimeout(() => confirmInput.focus(), 500);
}

// 导出函数（如果支持模块系统）
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        showDoubleConfirmDelete,
        showBatchDoubleConfirmDelete,
        DoubleConfirmConfig
    };
}
