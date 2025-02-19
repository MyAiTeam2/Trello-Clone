<?php
require_once '../session_start.php';
require_once '../onboarding/config.php';
require_once '../db_connection.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];

// Function to set up database tables
function setupDatabase($conn, $userId) {
    // Create tables if they don't exist
    $tables = [
        "trello_folders" => "
            CREATE TABLE IF NOT EXISTS trello_folders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )",
        "trello_boards" => "
            CREATE TABLE IF NOT EXISTS trello_boards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                folder_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (folder_id) REFERENCES trello_folders(id)
            )",
        "trello_columns" => "
            CREATE TABLE IF NOT EXISTS trello_columns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                board_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                FOREIGN KEY (board_id) REFERENCES trello_boards(id)
            )",
        "trello_cards" => "
            CREATE TABLE IF NOT EXISTS trello_cards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                column_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                due_date DATE,
                label VARCHAR(50),
                FOREIGN KEY (column_id) REFERENCES trello_columns(id)
            )",
        "trello_checklists" => "
            CREATE TABLE IF NOT EXISTS trello_checklists (
                id INT AUTO_INCREMENT PRIMARY KEY,
                card_id INT NOT NULL,
                text VARCHAR(255) NOT NULL,
                completed TINYINT(1) DEFAULT 0,
                FOREIGN KEY (card_id) REFERENCES trello_cards(id)
            )"
    ];

    foreach ($tables as $tableName => $sql) {
        $conn->exec($sql);
    }

    // Ensure "Other" folder exists for the user
    $stmt = $conn->prepare("SELECT id FROM trello_folders WHERE user_id = ? AND name = 'Other'");
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        $stmt = $conn->prepare("INSERT INTO trello_folders (user_id, name) VALUES (?, 'Other')");
        $stmt->execute([$userId]);
    }
}

// Run setup on page load
try {
    setupDatabase($conn, $userId);
} catch (PDOException $e) {
    die("Database setup failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trello Pro</title>
    <style>
        :root {
            --primary: #0079bf;
            --secondary: #f5f6f8;
            --text: #172b4d;
            --card-bg: #ffffff;
            --shadow: 0 4px 15px rgba(0,0,0,0.1);
            --bg-gradient: linear-gradient(135deg, #f5f6f8 0%, #dfe9f3 100%);
            --btn-secondary: #dfe1e6;
            --add-gradient: linear-gradient(135deg, #f6f8fa, #e9ecef);
            --add-hover: linear-gradient(135deg, #e9ecef, #dfe2e6);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        .dark-mode {
            --primary: #3b82f6;
            --secondary: #1f2a44;
            --text: #d1d5db;
            --card-bg: #2d3748;
            --shadow: 0 4px 15px rgba(0,0,0,0.5);
            --bg-gradient: linear-gradient(135deg, #1f2a44 0%, #374151 100%);
            --btn-secondary: #4b5563;
            --add-gradient: linear-gradient(135deg, #374151, #4b5563);
            --add-hover: linear-gradient(135deg, #4b5563, #6b7280);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-gradient);
            color: var(--text);
            line-height: 1.6;
            transition: var(--transition);
            overflow-x: hidden;
        }

        .app-container {
            display: flex;
            height: 100vh;
            position: relative;
        }

        .sidebar {
            width: 250px;
            background: var(--primary);
            color: white;
            padding: 20px;
            position: fixed;
            height: 100%;
            box-shadow: var(--shadow);
            overflow-y: auto;
            transition: var(--transition);
            z-index: 1000;
        }

        .sidebar h2 {
            font-size: 1.5rem;
            margin-bottom: 20px;
            font-weight: 700;
        }

        .sidebar-section {
            margin-bottom: 25px;
        }

        .add-new-btn {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            border-radius: 8px;
            background: linear-gradient(135deg, #ffffff, #e5e7eb);
            color: var(--primary);
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 10px;
        }

        .dark-mode .add-new-btn {
            background: linear-gradient(135deg, #4b5563, #6b7280);
            color: #ffffff;
        }

        .add-new-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            background: linear-gradient(135deg, #e5e7eb, #d1d5db);
        }

        .dark-mode .add-new-btn:hover {
            background: linear-gradient(135deg, #6b7280, #9ca3af);
        }

        .add-new-btn span {
            margin-right: 8px;
            font-size: 1.2rem;
        }

        .folder {
            margin-bottom: 15px;
        }

        .folder-header {
            padding: 10px 15px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255,255,255,0.1);
            transition: var(--transition);
        }

        .folder-header:hover {
            background: rgba(255,255,255,0.2);
        }

        .folder-actions {
            opacity: 0;
            transition: var(--transition);
        }

        .folder-header:hover .folder-actions {
            opacity: 1;
        }

        .folder-boards {
            padding-left: 15px;
            display: none;
        }

        .folder-boards.open {
            display: block;
        }

        .sidebar-item {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 5px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: var(--transition);
            font-size: 1rem;
        }

        .sidebar-item:hover {
            background: rgba(255,255,255,0.15);
        }

        .sidebar-item-actions {
            opacity: 0;
            transition: var(--transition);
        }

        .sidebar-item:hover .sidebar-item-actions {
            opacity: 1;
        }

        .hamburger {
            display: none;
            font-size: 1.5rem;
            background: none;
            border: none;
            color: var(--text);
            cursor: pointer;
            padding: 10px;
            position: fixed;
            top: 10px;
            left: 10px;
            z-index: 1100;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 20px;
            overflow: auto;
            transition: var(--transition);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 25px;
            background: var(--card-bg);
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            cursor: pointer;
        }

        .header-tools {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-secondary {
            background: var(--btn-secondary);
            color: var(--text);
        }

        .btn:hover {
            transform: translateY(-1px);
            filter: brightness(110%);
        }

        .board-container {
            display: flex;
            gap: 20px;
            padding-bottom: 20px;
            overflow-x: auto;
        }

        .column {
            background: var(--card-bg);
            border-radius: 12px;
            width: 300px;
            padding: 15px;
            flex-shrink: 0;
            box-shadow: var(--shadow);
            transition: var(--transition);
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .column-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .column-title {
            font-weight: 600;
            font-size: 1.1rem;
            background: linear-gradient(90deg, var(--primary), #00c4cc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            cursor: pointer;
        }

        .card-count {
            background: var(--primary);
            color: white;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 0.9rem;
        }

        .card {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            min-height: 80px;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.3);
        }

        .card-label {
            width: 8px;
            height: 100%;
            border-radius: 4px 0 0 4px;
            position: absolute;
            left: 0;
            top: 0;
        }

        .card-content {
            margin-left: 12px;
            flex: 1;
            max-width: calc(100% - 40px);
            position: relative;
        }

        .card-title {
            font-weight: 500;
            margin-bottom: 5px;
            font-size: 1rem;
        }

        .card-desc {
            font-size: 0.85rem;
            color: #6b7280;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 100%;
        }

        .card-checklist {
            font-size: 0.85rem;
            color: #9ca3af;
            margin-top: 5px;
        }

        .card-actions {
            opacity: 0;
            transition: var(--transition);
            margin-left: 10px;
            font-size: 1.2rem;
        }

        .card:hover .card-actions {
            opacity: 1;
        }

        .due-date {
            font-size: 0.85rem;
            color: #9ca3af;
            margin-top: 5px;
        }

        .checklist-preview {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: var(--card-bg);
            border-radius: 8px;
            padding: 10px;
            box-shadow: var(--shadow);
            z-index: 10;
            max-width: 250px;
            animation: fadeIn 0.2s ease;
        }

        .card:hover .checklist-preview, .card.tapped .checklist-preview {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .checklist-preview-item {
            display: flex;
            align-items: center;
            font-size: 0.85rem;
            margin-bottom: 5px;
            color: var(--text);
        }

        .checklist-preview-item input {
            margin-right: 8px;
        }

        .add-card, .add-column {
            color: var(--text);
            cursor: pointer;
            padding: 12px;
            border-radius: 8px;
            background: var(--add-gradient);
            text-align: center;
            transition: var(--transition);
            font-size: 1rem;
        }

        .add-card:hover, .add-column:hover {
            background: var(--add-hover);
            transform: translateY(-2px);
        }

        .delete-column {
            opacity: 0;
            cursor: pointer;
            transition: var(--transition);
            font-size: 1.2rem;
        }

        .column:hover .delete-column {
            opacity: 0.7;
        }

        .dragging {
            opacity: 0.7;
            transform: rotate(3deg) scale(1.02);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            align-items: center;
            justify-content: center;
            z-index: 1200;
        }

        .modal-content {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: var(--shadow);
            animation: modalPop 0.3s ease forwards;
        }

        @keyframes modalPop {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .modal-content input, .modal-content textarea, .modal-content select {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #4b5563;
            border-radius: 6px;
            font-size: 1rem;
            background: var(--card-bg);
            color: var(--text);
            transition: var(--transition);
        }

        .modal-content input:focus, .modal-content textarea:focus, .modal-content select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.3);
        }

        .modal-content textarea {
            min-height: 100px;
        }

        .checklist-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            gap: 10px;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .search-bar {
            padding: 8px 15px;
            width: 100%;
            max-width: 200px;
            border-radius: 6px;
            border: 1px solid #4b5563;
            background: var(--card-bg);
            color: var(--text);
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 0;
                overflow: hidden;
                padding: 0;
            }

            .sidebar.open {
                width: 250px;
                padding: 20px;
            }

            .hamburger {
                display: block;
            }

            .main-content {
                margin-left: 0;
                padding: 60px 10px 10px;
            }

            .header {
                padding: 10px 15px;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .header h1 {
                font-size: 1.2rem;
            }

            .header-tools {
                width: 100%;
                justify-content: space-between;
            }

            .board-container {
                flex-direction: column;
                align-items: stretch;
                overflow-x: hidden;
            }

            .column {
                width: 100%;
                margin-bottom: 20px;
            }

            .card {
                min-height: 100px;
            }

            .checklist-preview {
                top: auto;
                bottom: 100%;
                left: 50%;
                transform: translateX(-50%);
                max-width: 90%;
            }

            .add-card, .add-column {
                padding: 10px;
            }

            .modal-content {
                width: 95%;
                padding: 15px;
            }

            .btn {
                padding: 8px 12px;
                font-size: 0.85rem;
            }

            .add-new-btn {
                padding: 8px 12px;
            }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <button class="hamburger" onclick="document.querySelector('.sidebar').classList.toggle('open')">☰</button>
    <div class="app-container">
        <div class="sidebar" id="sidebar">
            <h2>Trello Pro</h2>
            <div class="sidebar-section">
                <div class="add-new-btn" onclick="trello.showBoardCreator(); document.querySelector('.sidebar').classList.remove('open')"><span>+</span> New Board</div>
                <div class="add-new-btn" onclick="trello.showNewFolder(); document.querySelector('.sidebar').classList.remove('open')"><span>+</span> New Folder</div>
                <div class="add-new-btn" onclick="trello.showTemplates(); document.querySelector('.sidebar').classList.remove('open')"><span>📋</span> Templates</div>
            </div>
            <div class="sidebar-section" id="folderList"></div>
        </div>
        <div class="main-content">
            <div class="header">
                <h1 id="boardTitle">Project Board</h1>
                <div class="header-tools">
                    <input type="text" class="search-bar" placeholder="Search cards..." oninput="trello.searchCards(this.value)">
                    <button class="btn btn-primary" onclick="document.body.classList.toggle('dark-mode')">Dark Mode</button>
                    <button class="btn btn-secondary" onclick="trello.exportBoard()">Export</button>
                </div>
            </div>
            <div class="board-container" id="boardContainer"></div>
            <div class="modal" id="cardModal">
                <div class="modal-content">
                    <h2>Card Details</h2>
                    <input type="text" id="cardTitle" placeholder="Card Title">
                    <textarea id="cardDesc" placeholder="Description"></textarea>
                    <input type="date" id="cardDueDate">
                    <select id="cardLabel">
                        <option value="">No Label</option>
                        <option value="#2ecc71">Green</option>
                        <option value="#e74c3c">Red</option>
                        <option value="#3498db">Blue</option>
                        <option value="#f1c40f">Yellow</option>
                    </select>
                    <div id="checklist"></div>
                    <div class="modal-buttons">
                        <button class="btn btn-primary" onclick="trello.addChecklistItem()">Add Checklist</button>
                        <button class="btn btn-primary" onclick="trello.saveCardDetails()">Save</button>
                        <button class="btn btn-secondary" onclick="document.getElementById('cardModal').style.display='none'">Close</button>
                    </div>
                </div>
            </div>
            <div class="modal" id="boardCreatorModal">
                <div class="modal-content">
                    <h2>Create New Board</h2>
                    <input type="text" id="newBoardName" placeholder="Board Name">
                    <select id="folderSelect"></select>
                    <div class="modal-buttons">
                        <button class="btn btn-primary" onclick="trello.createBoard()">Create</button>
                        <button class="btn btn-secondary" onclick="document.getElementById('boardCreatorModal').style.display='none'">Cancel</button>
                    </div>
                </div>
            </div>
            <div class="modal" id="newFolderModal">
                <div class="modal-content">
                    <h2>Create New Folder</h2>
                    <input type="text" id="newFolderName" placeholder="Folder Name">
                    <div class="modal-buttons">
                        <button class="btn btn-primary" onclick="trello.createFolder()">Create</button>
                        <button class="btn btn-secondary" onclick="document.getElementById('newFolderModal').style.display='none'">Cancel</button>
                    </div>
                </div>
            </div>
            <div class="modal" id="templatesModal">
                <div class="modal-content">
                    <h2>Templates</h2>
                    <div id="templateList"></div>
                    <div class="modal-buttons">
                        <button class="btn btn-secondary" onclick="document.getElementById('templatesModal').style.display='none'">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        class TrelloClone {
            constructor() {
                this.currentBoardId = null;
                this.templates = {
                    'Project Management': [
                        { title: 'To Do', cards: [{ title: 'Plan Project', desc: 'Set goals and timeline', due_date: '', label: '', checklist: [{ text: 'Define scope', completed: false }] }] },
                        { title: 'In Progress', cards: [] },
                        { title: 'Review', cards: [] },
                        { title: 'Done', cards: [] }
                    ],
                    'Personal Tasks': [
                        { title: 'To Do', cards: [{ title: 'Grocery Shopping', desc: 'Get milk, bread, eggs', due_date: '', label: '', checklist: [{ text: 'Milk', completed: false }, { text: 'Bread', completed: true }] }] },
                        { title: 'Done', cards: [] }
                    ],
                    'Software Dev': [
                        { title: 'Backlog', cards: [{ title: 'Implement Feature X', desc: 'Add new API endpoint', due_date: '', label: '', checklist: [{ text: 'Write tests', completed: false }] }] },
                        { title: 'Current Sprint', cards: [] },
                        { title: 'Testing', cards: [] },
                        { title: 'Deployed', cards: [] }
                    ]
                };
                this.fetchData().then(() => {
                    this.renderSidebar();
                    this.renderBoard();
                    this.setupDragAndDrop();
                    this.setupAddColumn();
                    this.setupCardInteractions();
                });
            }

            async fetchData() {
                const response = await fetch('trello_api.php?action=get_data');
                const data = await response.json();
                this.folders = data.folders;
                this.boards = data.boards;
                this.columns = data.columns;
                this.cards = data.cards;
                this.checklists = data.checklists;

                if (!this.currentBoardId && Object.keys(this.boards).length > 0) {
                    this.currentBoardId = Object.keys(this.boards)[0];
                }
            }

            async apiRequest(method, action, body = {}) {
                const response = await fetch('trello_api.php', {
                    method,
                    headers: { 'Content-Type': 'application/json' },
                    body: method !== 'GET' ? JSON.stringify({ action, ...body }) : undefined
                });
                return await response.json();
            }

            async createFolder() {
                const name = document.getElementById('newFolderName').value;
                if (name) {
                    const result = await this.apiRequest('POST', 'create_folder', { name });
                    this.folders[result.id] = { id: result.id, name };
                    this.renderSidebar();
                    document.getElementById('newFolderModal').style.display = 'none';
                }
            }

            async editFolder(folderId) {
                const newName = prompt('Enter new folder name:', this.folders[folderId].name);
                if (newName && newName !== this.folders[folderId].name) {
                    await this.apiRequest('POST', 'update_folder', { id: folderId, name: newName });
                    this.folders[folderId].name = newName;
                    Object.values(this.boards).forEach(board => {
                        if (board.folder_id == folderId) board.folder_id = folderId; // Update frontend only (DB handled by API)
                    });
                    this.renderSidebar();
                }
            }

            async deleteFolder(folderId) {
                if (confirm(`Delete folder "${this.folders[folderId].name}"? Boards will move to "Other".`)) {
                    await this.apiRequest('DELETE', 'delete_folder', { id: folderId });
                    await this.fetchData(); // Refresh all data
                    this.renderSidebar();
                    this.renderBoard();
                }
            }

            async createBoard() {
                const name = document.getElementById('newBoardName').value;
                const folderId = document.getElementById('folderSelect').value;
                if (name) {
                    const result = await this.apiRequest('POST', 'create_board', { name, folder_id: folderId });
                    await this.fetchData();
                    this.currentBoardId = result.id;
                    this.renderSidebar();
                    this.renderBoard();
                    document.getElementById('boardCreatorModal').style.display = 'none';
                }
            }

            async editBoardName() {
                const newName = prompt('Enter new board name:', this.boards[this.currentBoardId].name);
                if (newName) {
                    await this.apiRequest('POST', 'update_board', { id: this.currentBoardId, name: newName });
                    this.boards[this.currentBoardId].name = newName;
                    this.renderSidebar();
                    this.renderBoard();
                }
            }

            async deleteBoard(boardId) {
                if (confirm('Delete this board and all its contents?')) {
                    await this.apiRequest('DELETE', 'delete_board', { id: boardId });
                    await this.fetchData();
                    if (this.currentBoardId === boardId) {
                        this.currentBoardId = Object.keys(this.boards)[0] || null;
                    }
                    this.renderSidebar();
                    this.renderBoard();
                }
            }

            renderSidebar() {
                const folderList = document.getElementById('folderList');
                folderList.innerHTML = '';
                Object.entries(this.folders).forEach(([folderId, folder]) => {
                    const boardIds = Object.values(this.boards).filter(b => b.folder_id == folderId).map(b => b.id);
                    const folderEl = document.createElement('div');
                    folderEl.className = 'folder';
                    folderEl.innerHTML = `
                        <div class="folder-header" onclick="this.nextElementSibling.classList.toggle('open')">
                            <span>${folder.name} (${boardIds.length})</span>
                            <span class="folder-actions">
                                <span onclick="event.stopPropagation(); trello.editFolder('${folderId}')">✎</span>
                                <span onclick="event.stopPropagation(); trello.deleteFolder('${folderId}')">×</span>
                            </span>
                            <span>▼</span>
                        </div>
                        <div class="folder-boards"></div>
                    `;
                    const boardsContainer = folderEl.querySelector('.folder-boards');
                    boardIds.forEach(id => {
                        const item = document.createElement('div');
                        item.className = 'sidebar-item';
                        item.innerHTML = `
                            <span onclick="trello.switchBoard('${id}'); document.querySelector('.sidebar').classList.remove('open')">${this.boards[id].name}</span>
                            <span class="sidebar-item-actions">
                                <span onclick="trello.deleteBoard('${id}')">×</span>
                            </span>
                        `;
                        if (id === this.currentBoardId) item.style.background = 'rgba(255,255,255,0.2)';
                        boardsContainer.appendChild(item);
                    });
                    folderList.appendChild(folderEl);
                });

                const folderSelect = document.getElementById('folderSelect');
                folderSelect.innerHTML = '';
                Object.entries(this.folders).forEach(([id, folder]) => {
                    const option = document.createElement('option');
                    option.value = id;
                    option.textContent = folder.name;
                    folderSelect.appendChild(option);
                });
            }

            renderBoard() {
                if (!this.currentBoardId) return;
                const board = this.boards[this.currentBoardId];
                document.getElementById('boardTitle').textContent = board.name;
                const container = document.getElementById('boardContainer');
                container.innerHTML = '';

                const boardColumns = Object.values(this.columns).filter(col => col.board_id == this.currentBoardId);
                boardColumns.forEach(column => {
                    const columnEl = document.createElement('div');
                    columnEl.className = 'column';
                    columnEl.dataset.columnId = column.id;

                    columnEl.innerHTML = `
                        <div class="column-header">
                            <span class="column-title" onclick="trello.editColumnTitle('${column.id}')">${column.title}</span>
                            <span class="card-count">${Object.values(this.cards).filter(card => card.column_id == column.id).length}</span>
                            <span class="delete-column" onclick="trello.deleteColumn('${column.id}')">×</span>
                        </div>
                        <div class="cards-container"></div>
                        <div class="add-card">+ Add Card</div>
                    `;

                    const cardsContainer = columnEl.querySelector('.cards-container');
                    Object.values(this.cards).filter(card => card.column_id == column.id).forEach(card => {
                        const cardEl = document.createElement('div');
                        cardEl.className = 'card';
                        cardEl.draggable = true;
                        cardEl.dataset.cardId = card.id;
                        const checklist = Object.values(this.checklists).filter(item => item.card_id == card.id);
                        const checklistCount = checklist.length > 0 ? `${checklist.filter(item => item.completed).length}/${checklist.length}` : '';
                        cardEl.innerHTML = `
                            ${card.label ? `<span class="card-label" style="background: ${card.label}"></span>` : ''}
                            <div class="card-content">
                                <div class="card-title">${card.title}</div>
                                ${card.description ? `<div class="card-desc">${card.description}</div>` : ''}
                                ${card.due_date ? `<div class="due-date">Due: ${new Date(card.due_date).toLocaleDateString()}</div>` : ''}
                                ${checklistCount ? `<div class="card-checklist">Checklist: ${checklistCount}</div>` : ''}
                                ${checklist.length > 0 ? `<div class="checklist-preview">${checklist.map(item => `<div class="checklist-preview-item"><input type="checkbox" ${item.completed ? 'checked' : ''} disabled>${item.text}</div>`).join('')}</div>` : ''}
                            </div>
                            <span class="card-actions">
                                <span onclick="trello.editCard('${column.id}', '${card.id}')">✎</span>
                                <span onclick="trello.deleteCard('${column.id}', '${card.id}')">×</span>
                            </span>
                        `;
                        cardsContainer.appendChild(cardEl);
                    });

                    columnEl.querySelector('.add-card').addEventListener('click', () => this.addCard(column.id));
                    container.appendChild(columnEl);
                });

                const addColumnEl = document.createElement('div');
                addColumnEl.className = 'column add-column';
                addColumnEl.innerHTML = '+ Add Column';
                container.appendChild(addColumnEl);
            }

            switchBoard(boardId) {
                this.currentBoardId = boardId;
                this.renderSidebar();
                this.renderBoard();
            }

            async addCard(columnId) {
                const result = await this.apiRequest('POST', 'create_card', { column_id: columnId, title: 'New Task' });
                await this.fetchData();
                this.renderBoard();
            }

            async editColumnTitle(columnId) {
                const newTitle = prompt('Enter new column title:', this.columns[columnId].title);
                if (newTitle) {
                    await this.apiRequest('POST', 'update_column', { id: columnId, title: newTitle });
                    this.columns[columnId].title = newTitle;
                    this.renderBoard();
                }
            }

            async deleteColumn(columnId) {
                if (confirm('Delete this column and all its cards?')) {
                    await this.apiRequest('DELETE', 'delete_column', { id: columnId });
                    await this.fetchData();
                    this.renderBoard();
                }
            }

            async editCard(columnId, cardId) {
                const card = this.cards[cardId];
                this.currentCard = { columnId, cardId };

                const modal = document.getElementById('cardModal');
                document.getElementById('cardTitle').value = card.title;
                document.getElementById('cardDesc').value = card.description || '';
                document.getElementById('cardDueDate').value = card.due_date || '';
                document.getElementById('cardLabel').value = card.label || '';
                
                const checklist = document.getElementById('checklist');
                checklist.innerHTML = '';
                Object.values(this.checklists).filter(item => item.card_id == cardId).forEach((item, index) => {
                    const div = document.createElement('div');
                    div.className = 'checklist-item';
                    div.innerHTML = `
                        <input type="checkbox" ${item.completed ? 'checked' : ''} onchange="trello.updateChecklist('${item.id}', this.checked)">
                        <input type="text" value="${item.text}" onblur="trello.updateChecklistText('${item.id}', this.value)">
                        <span onclick="trello.deleteChecklistItem('${item.id}')">×</span>
                    `;
                    checklist.appendChild(div);
                });

                modal.style.display = 'flex';
            }

            async deleteCard(columnId, cardId) {
                if (confirm('Delete this card?')) {
                    await this.apiRequest('DELETE', 'delete_card', { id: cardId });
                    await this.fetchData();
                    this.renderBoard();
                }
            }

            async addChecklistItem() {
                const { cardId } = this.currentCard;
                await this.apiRequest('POST', 'create_checklist', { card_id: cardId, text: 'New Item' });
                await this.fetchData();
                this.editCard(this.currentCard.columnId, cardId);
            }

            async updateChecklist(checklistId, completed) {
                await this.apiRequest('POST', 'update_checklist', { id: checklistId, text: this.checklists[checklistId].text, completed: completed ? 1 : 0 });
                this.checklists[checklistId].completed = completed;
            }

            async updateChecklistText(checklistId, text) {
                await this.apiRequest('POST', 'update_checklist', { id: checklistId, text, completed: this.checklists[checklistId].completed });
                this.checklists[checklistId].text = text;
            }

            async deleteChecklistItem(checklistId) {
                await this.apiRequest('DELETE', 'delete_checklist', { id: checklistId });
                await this.fetchData();
                this.editCard(this.currentCard.columnId, this.currentCard.cardId);
            }

            async saveCardDetails() {
                const { columnId, cardId } = this.currentCard;
                const card = this.cards[cardId];
                card.title = document.getElementById('cardTitle').value;
                card.description = document.getElementById('cardDesc').value;
                card.due_date = document.getElementById('cardDueDate').value;
                card.label = document.getElementById('cardLabel').value;
                await this.apiRequest('POST', 'update_card', { 
                    id: cardId, 
                    title: card.title, 
                    description: card.description, 
                    due_date: card.due_date, 
                    label: card.label, 
                    column_id: columnId 
                });
                await this.fetchData();
                this.renderBoard();
                document.getElementById('cardModal').style.display = 'none';
            }

            setupDragAndDrop() {
                document.addEventListener('dragstart', (e) => {
                    if (!e.target.classList.contains('card')) return;
                    e.target.classList.add('dragging');
                });

                document.addEventListener('dragend', (e) => {
                    if (!e.target.classList.contains('card')) return;
                    e.target.classList.remove('dragging');
                });

                document.addEventListener('dragover', (e) => {
                    e.preventDefault();
                });

                document.addEventListener('drop', async (e) => {
                    e.preventDefault();
                    const card = document.querySelector('.dragging');
                    if (!card) return;

                    const cardId = card.dataset.cardId;
                    const columnEl = e.target.closest('.column');
                    if (!columnEl || !columnEl.dataset.columnId) return;

                    const targetColumnId = columnEl.dataset.columnId;
                    await this.apiRequest('POST', 'move_card', { card_id: cardId, column_id: targetColumnId });
                    await this.fetchData();
                    this.renderBoard();
                });
            }

            setupAddColumn() {
                document.addEventListener('click', async (e) => {
                    if (e.target.classList.contains('add-column')) {
                        const title = prompt('Enter column title:');
                        if (title) {
                            await this.apiRequest('POST', 'create_column', { board_id: this.currentBoardId, title });
                            await this.fetchData();
                            this.renderBoard();
                        }
                    }
                });
            }

            setupCardInteractions() {
                document.addEventListener('click', (e) => {
                    if (e.target.closest('.card') && !e.target.closest('.card-actions')) {
                        const cardEl = e.target.closest('.card');
                        cardEl.classList.toggle('tapped');
                        setTimeout(() => cardEl.classList.remove('tapped'), 2000);
                    }
                });
            }

            showBoardCreator() {
                document.getElementById('boardCreatorModal').style.display = 'flex';
                this.renderSidebar();
            }

            showNewFolder() {
                document.getElementById('newFolderModal').style.display = 'flex';
            }

            showTemplates() {
                const templateList = document.getElementById('templateList');
                templateList.innerHTML = '';
                Object.entries(this.templates).forEach(([name, columns]) => {
                    const item = document.createElement('div');
                    item.className = 'sidebar-item';
                    item.textContent = name;
                    item.onclick = () => this.applyTemplate(name);
                    templateList.appendChild(item);
                });
                document.getElementById('templatesModal').style.display = 'flex';
            }

            async applyTemplate(templateName) {
                const folderId = Object.keys(this.folders).find(id => this.folders[id].name === 'Templates') || Object.keys(this.folders)[0];
                const result = await this.apiRequest('POST', 'create_board', { name: templateName, folder_id: folderId });
                const boardId = result.id;
                for (const column of this.templates[templateName]) {
                    const colResult = await this.apiRequest('POST', 'create_column', { board_id: boardId, title: column.title });
                    for (const card of column.cards) {
                        const cardResult = await this.apiRequest('POST', 'create_card', { column_id: colResult.id, title: card.title });
                        if (card.desc) {
                            await this.apiRequest('POST', 'update_card', { id: cardResult.id, title: card.title, description: card.desc, due_date: card.due_date, label: card.label, column_id: colResult.id });
                        }
                        for (const item of card.checklist) {
                            await this.apiRequest('POST', 'create_checklist', { card_id: cardResult.id, text: item.text });
                            await this.apiRequest('POST', 'update_checklist', { id: this.conn.lastInsertId(), text: item.text, completed: item.completed ? 1 : 0 });
                        }
                    }
                }
                await this.fetchData();
                this.currentBoardId = boardId;
                this.renderSidebar();
                this.renderBoard();
                document.getElementById('templatesModal').style.display = 'none';
            }

            exportBoard() {
                const boardData = {
                    name: this.boards[this.currentBoardId].name,
                    columns: Object.values(this.columns).filter(col => col.board_id == this.currentBoardId).map(col => ({
                        id: col.id,
                        title: col.title,
                        cards: Object.values(this.cards).filter(card => card.column_id == col.id).map(card => ({
                            id: card.id,
                            title: card.title,
                            description: card.description,
                            due_date: card.due_date,
                            label: card.label,
                            checklist: Object.values(this.checklists).filter(item => item.card_id == card.id)
                        }))
                    }))
                };
                const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(boardData));
                const downloadAnchor = document.createElement('a');
                downloadAnchor.setAttribute("href", dataStr);
                downloadAnchor.setAttribute("download", `${this.boards[this.currentBoardId].name}.json`);
                downloadAnchor.click();
            }

            async searchCards(query) {
                const board = this.boards[this.currentBoardId];
                const container = document.getElementById('boardContainer');
                container.innerHTML = '';

                const boardColumns = Object.values(this.columns).filter(col => col.board_id == this.currentBoardId);
                boardColumns.forEach(column => {
                    const columnEl = document.createElement('div');
                    columnEl.className = 'column';
                    columnEl.dataset.columnId = column.id;

                    columnEl.innerHTML = `
                        <div class="column-header">
                            <span class="column-title" onclick="trello.editColumnTitle('${column.id}')">${column.title}</span>
                            <span class="card-count">${Object.values(this.cards).filter(card => card.column_id == column.id).length}</span>
                            <span class="delete-column" onclick="trello.deleteColumn('${column.id}')">×</span>
                        </div>
                        <div class="cards-container"></div>
                        <div class="add-card">+ Add Card</div>
                    `;

                    const cardsContainer = columnEl.querySelector('.cards-container');
                    Object.values(this.cards).filter(card => 
                        card.column_id == column.id && 
                        (card.title.toLowerCase().includes(query.toLowerCase()) || 
                         (card.description && card.description.toLowerCase().includes(query.toLowerCase())))
                    ).forEach(card => {
                        const cardEl = document.createElement('div');
                        cardEl.className = 'card';
                        cardEl.draggable = true;
                        cardEl.dataset.cardId = card.id;
                        const checklist = Object.values(this.checklists).filter(item => item.card_id == card.id);
                        const checklistCount = checklist.length > 0 ? `${checklist.filter(item => item.completed).length}/${checklist.length}` : '';
                        cardEl.innerHTML = `
                            ${card.label ? `<span class="card-label" style="background: ${card.label}"></span>` : ''}
                            <div class="card-content">
                                <div class="card-title">${card.title}</div>
                                ${card.description ? `<div class="card-desc">${card.description}</div>` : ''}
                                ${card.due_date ? `<div class="due-date">Due: ${new Date(card.due_date).toLocaleDateString()}</div>` : ''}
                                ${checklistCount ? `<div class="card-checklist">Checklist: ${checklistCount}</div>` : ''}
                                ${checklist.length > 0 ? `<div class="checklist-preview">${checklist.map(item => `<div class="checklist-preview-item"><input type="checkbox" ${item.completed ? 'checked' : ''} disabled>${item.text}</div>`).join('')}</div>` : ''}
                            </div>
                            <span class="card-actions">
                                <span onclick="trello.editCard('${column.id}', '${card.id}')">✎</span>
                                <span onclick="trello.deleteCard('${column.id}', '${card.id}')">×</span>
                            </span>
                        `;
                        cardsContainer.appendChild(cardEl);
                    });

                    columnEl.querySelector('.add-card').addEventListener('click', () => this.addCard(column.id));
                    container.appendChild(columnEl);
                });

                const addColumnEl = document.createElement('div');
                addColumnEl.className = 'column add-column';
                addColumnEl.innerHTML = '+ Add Column';
                container.appendChild(addColumnEl);
            }
        }

        const trello = new TrelloClone();
    </script>
</body>
</html>