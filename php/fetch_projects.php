<?php
require_once 'db.php'; // your PDO connection

// Fetch active projects
$stmt = $pdo->query("SELECT id, name, description, location, goal_amount, is_active FROM projects WHERE is_active = 1 ORDER BY created_at DESC");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($projects as &$project) {
    // Fetch images
    $stmtImg = $pdo->prepare("SELECT image_url FROM project_images WHERE project_id = ? ORDER BY sort_order ASC");
    $stmtImg->execute([$project['id']]);
    $project['images'] = $stmtImg->fetchAll(PDO::FETCH_COLUMN);

    // Calculate actual raised amount from donations
    $stmtRaised = $pdo->prepare("SELECT SUM(amount) FROM donations WHERE project_id = ? AND status = 'paid'");
    $stmtRaised->execute([$project['id']]);
    $project['raised'] = (float) $stmtRaised->fetchColumn() ?: 0;
}

header('Content-Type: application/json');
echo json_encode($projects);
