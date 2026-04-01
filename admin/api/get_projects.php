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

$company_id = isset($_GET['company_id']) ? intval($_GET['company_id']) : 0;

if ($company_id <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $query = "SELECT id, name FROM projects WHERE company_id = ? ORDER BY name ASC";
    $stmt = $db->prepare($query);
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($projects);
} catch (Exception $e) {
    echo json_encode([]);
}
?>