<?php
/**
 * 数据库连接封装
 * 提供统一的数据库连接接口
 */

// 加载数据库配置
require_once __DIR__ . '/../config/database.php';

/**
 * 获取数据库连接
 * @return PDO|null 数据库连接对象
 */
function get_db_connection() {
    static $db = null;
    
    if ($db === null) {
        $database = new Database();
        $db = $database->getConnection();
    }
    
    return $db;
}

/**
 * 执行查询并返回所有结果
 * @param string $sql SQL查询语句
 * @param array $params 查询参数
 * @return array 查询结果
 */
function db_query_all($sql, $params = []) {
    $pdo = get_db_connection();
    if (!$pdo) return [];
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("数据库查询错误: " . $e->getMessage());
        return [];
    }
}

/**
 * 执行查询并返回单行结果
 * @param string $sql SQL查询语句
 * @param array $params 查询参数
 * @return array|null 查询结果
 */
function db_query_row($sql, $params = []) {
    $pdo = get_db_connection();
    if (!$pdo) return null;
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("数据库查询错误: " . $e->getMessage());
        return null;
    }
}

/**
 * 执行更新操作
 * @param string $sql SQL更新语句
 * @param array $params 更新参数
 * @return int 影响的行数
 */
function db_execute($sql, $params = []) {
    $pdo = get_db_connection();
    if (!$pdo) return 0;
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("数据库执行错误: " . $e->getMessage());
        return 0;
    }
}

/**
 * 获取最后插入的ID
 * @return string 最后插入的ID
 */
function db_last_insert_id() {
    $pdo = get_db_connection();
    if (!$pdo) return '';
    
    return $pdo->lastInsertId();
}

/**
 * 开始事务
 * @return bool 是否成功
 */
function db_begin_transaction() {
    $pdo = get_db_connection();
    if (!$pdo) return false;
    
    try {
        return $pdo->beginTransaction();
    } catch (PDOException $e) {
        error_log("事务开始错误: " . $e->getMessage());
        return false;
    }
}

/**
 * 提交事务
 * @return bool 是否成功
 */
function db_commit() {
    $pdo = get_db_connection();
    if (!$pdo) return false;
    
    try {
        return $pdo->commit();
    } catch (PDOException $e) {
        error_log("事务提交错误: " . $e->getMessage());
        return false;
    }
}

/**
 * 回滚事务
 * @return bool 是否成功
 */
function db_rollback() {
    $pdo = get_db_connection();
    if (!$pdo) return false;
    
    try {
        return $pdo->rollBack();
    } catch (PDOException $e) {
        error_log("事务回滚错误: " . $e->getMessage());
        return false;
    }
}
?>