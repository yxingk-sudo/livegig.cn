<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode([]);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

if ($project_id <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $query = "SELECT id, name FROM departments WHERE project_id = ? ORDER BY name ASC";
    $stmt = $db->prepare($query);
    $stmt->execute([$project_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($departments);
} catch (Exception $e) {
    echo json_encode([]);
}
?>