<?php
require_once '../onboarding/config.php';
require_once '../db_connection.php'; // Assuming this sets up $conn with PDO

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'get_data':
                    $folders = $conn->query("SELECT id, name FROM trello_folders WHERE user_id = $userId")->fetchAll(PDO::FETCH_ASSOC);
                    $boards = $conn->query("SELECT id, folder_id, name FROM trello_boards WHERE user_id = $userId")->fetchAll(PDO::FETCH_ASSOC);
                    $columns = $conn->query("SELECT id, board_id, title FROM trello_columns WHERE board_id IN (SELECT id FROM trello_boards WHERE user_id = $userId)")->fetchAll(PDO::FETCH_ASSOC);
                    $cards = $conn->query("SELECT id, column_id, title, description, due_date, label FROM trello_cards WHERE column_id IN (SELECT id FROM trello_columns WHERE board_id IN (SELECT id FROM trello_boards WHERE user_id = $userId))")->fetchAll(PDO::FETCH_ASSOC);
                    $checklists = $conn->query("SELECT id, card_id, text, completed FROM trello_checklists WHERE card_id IN (SELECT id FROM trello_cards WHERE column_id IN (SELECT id FROM trello_columns WHERE board_id IN (SELECT id FROM trello_boards WHERE user_id = $userId)))")->fetchAll(PDO::FETCH_ASSOC);

                    $response = [
                        'folders' => array_column($folders, null, 'id'),
                        'boards' => array_column($boards, null, 'id'),
                        'columns' => array_column($columns, null, 'id'),
                        'cards' => array_column($cards, null, 'id'),
                        'checklists' => array_column($checklists, null, 'id')
                    ];
                    echo json_encode($response);
                    break;
            }
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        switch ($data['action']) {
            case 'create_folder':
                $stmt = $conn->prepare("INSERT INTO trello_folders (user_id, name) VALUES (?, ?)");
                $stmt->execute([$userId, $data['name']]);
                echo json_encode(['id' => $conn->lastInsertId()]);
                break;

            case 'create_board':
                $stmt = $conn->prepare("INSERT INTO trello_boards (user_id, folder_id, name) VALUES (?, ?, ?)");
                $stmt->execute([$userId, $data['folder_id'], $data['name']]);
                $boardId = $conn->lastInsertId();
                $defaultColumns = ['To Do', 'In Progress', 'Done'];
                foreach ($defaultColumns as $title) {
                    $stmt = $conn->prepare("INSERT INTO trello_columns (board_id, title) VALUES (?, ?)");
                    $stmt->execute([$boardId, $title]);
                }
                echo json_encode(['id' => $boardId]);
                break;

            case 'create_column':
                $stmt = $conn->prepare("INSERT INTO trello_columns (board_id, title) VALUES (?, ?)");
                $stmt->execute([$data['board_id'], $data['title']]);
                echo json_encode(['id' => $conn->lastInsertId()]);
                break;

            case 'create_card':
                $stmt = $conn->prepare("INSERT INTO trello_cards (column_id, title) VALUES (?, ?)");
                $stmt->execute([$data['column_id'], $data['title']]);
                echo json_encode(['id' => $conn->lastInsertId()]);
                break;

            case 'create_checklist':
                $stmt = $conn->prepare("INSERT INTO trello_checklists (card_id, text, completed) VALUES (?, ?, 0)");
                $stmt->execute([$data['card_id'], $data['text']]);
                echo json_encode(['id' => $conn->lastInsertId()]);
                break;

            case 'update_folder':
                $stmt = $conn->prepare("UPDATE trello_folders SET name = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$data['name'], $data['id'], $userId]);
                echo json_encode(['success' => true]);
                break;

            case 'update_board':
                $stmt = $conn->prepare("UPDATE trello_boards SET name = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$data['name'], $data['id'], $userId]);
                echo json_encode(['success' => true]);
                break;

            case 'update_column':
                $stmt = $conn->prepare("UPDATE trello_columns SET title = ? WHERE id = ? AND board_id IN (SELECT id FROM trello_boards WHERE user_id = ?)");
                $stmt->execute([$data['title'], $data['id'], $userId]);
                echo json_encode(['success' => true]);
                break;

            case 'update_card':
                $stmt = $conn->prepare("UPDATE trello_cards SET title = ?, description = ?, due_date = ?, label = ?, column_id = ? WHERE id = ? AND column_id IN (SELECT id FROM trello_columns WHERE board_id IN (SELECT id FROM trello_boards WHERE user_id = ?))");
                $stmt->execute([$data['title'], $data['description'], $data['due_date'], $data['label'], $data['column_id'], $data['id'], $userId]);
                echo json_encode(['success' => true]);
                break;

            case 'update_checklist':
                $stmt = $conn->prepare("UPDATE trello_checklists SET text = ?, completed = ? WHERE id = ? AND card_id IN (SELECT id FROM trello_cards WHERE column_id IN (SELECT id FROM trello_columns WHERE board_id IN (SELECT id FROM trello_boards WHERE user_id = ?)))");
                $stmt->execute([$data['text'], $data['completed'], $data['id'], $userId]);
                echo json_encode(['success' => true]);
                break;

            case 'move_card':
                $stmt = $conn->prepare("UPDATE trello_cards SET column_id = ? WHERE id = ? AND column_id IN (SELECT id FROM trello_columns WHERE board_id IN (SELECT id FROM trello_boards WHERE user_id = ?))");
                $stmt->execute([$data['column_id'], $data['card_id'], $userId]);
                echo json_encode(['success' => true]);
                break;
        }
        break;

    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        switch ($data['action']) {
            case 'delete_folder':
                $stmt = $conn->prepare("SELECT id FROM trello_boards WHERE folder_id = ? AND user_id = ?");
                $stmt->execute([$data['id'], $userId]);
                $boards = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($boards as $boardId) {
                    $conn->prepare("UPDATE trello_boards SET folder_id = (SELECT id FROM trello_folders WHERE name = 'Other' AND user_id = ?) WHERE id = ?")->execute([$userId, $boardId]);
                }
                $stmt = $conn->prepare("DELETE FROM trello_folders WHERE id = ? AND user_id = ?");
                $stmt->execute([$data['id'], $userId]);
                echo json_encode(['success' => true]);
                break;

            case 'delete_board':
                $stmt = $conn->prepare("DELETE FROM trello_boards WHERE id = ? AND user_id = ?");
                $stmt->execute([$data['id'], $userId]);
                echo json_encode(['success' => true]);
                break;

            case 'delete_column':
                $stmt = $conn->prepare("DELETE FROM trello_columns WHERE id = ? AND board_id IN (SELECT id FROM trello_boards WHERE user_id = ?)");
                $stmt->execute([$data['id'], $userId]);
                echo json_encode(['success' => true]);
                break;

            case 'delete_card':
                $stmt = $conn->prepare("DELETE FROM trello_cards WHERE id = ? AND column_id IN (SELECT id FROM trello_columns WHERE board_id IN (SELECT id FROM trello_boards WHERE user_id = ?))");
                $stmt->execute([$data['id'], $userId]);
                echo json_encode(['success' => true]);
                break;

            case 'delete_checklist':
                $stmt = $conn->prepare("DELETE FROM trello_checklists WHERE id = ? AND card_id IN (SELECT id FROM trello_cards WHERE column_id IN (SELECT id FROM trello_columns WHERE board_id IN (SELECT id FROM trello_boards WHERE user_id = ?)))");
                $stmt->execute([$data['id'], $userId]);
                echo json_encode(['success' => true]);
                break;
        }
        break;
}
?>