<?php
// --------------------
// HTTP BASIC AUTHENTICATION
// --------------------

$admin_login = 'admin';
$admin_password = 'admin123';

if (!isset($_SERVER['PHP_AUTH_USER']) || 
    $_SERVER['PHP_AUTH_USER'] != $admin_login || 
    $_SERVER['PHP_AUTH_PW'] != $admin_password) {
    
    header('HTTP/1.1 401 Unauthorized');
    header('WWW-Authenticate: Basic realm="Admin Panel"');
    echo '<!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <title>Доступ запрещен</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background: #800020;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
            }
            .error-box {
                background: white;
                padding: 40px;
                border-radius: 20px;
                text-align: center;
                box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            }
            .error-box h1 { color: #800020; margin-bottom: 20px; }
            .error-box p { color: #666; margin-bottom: 20px; }
            .error-box button {
                background: #9E9E9E;
                color: #800020;
                border: none;
                padding: 10px 30px;
                border-radius: 10px;
                cursor: pointer;
                font-size: 16px;
            }
            .error-box button:hover { background: #757575; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h1>🔒 Доступ запрещен</h1>
            <p>Требуется авторизация для доступа к админ-панели</p>
            <button onclick="window.location.reload()">Попробовать снова</button>
        </div>
    </body>
    </html>';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');

// --------------------
// ПОДКЛЮЧЕНИЕ К БД
// --------------------

$db_user = 'u82464';
$db_pass = '8104996';
$db_name = 'u82464';
$db_host = 'localhost';

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch(PDOException $e) {
    die('Ошибка подключения к базе данных: ' . $e->getMessage());
}

// --------------------
// СОЗДАНИЕ ТАБЛИЦ
// --------------------

$pdo->exec("
    CREATE TABLE IF NOT EXISTS applications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        email VARCHAR(255) NOT NULL,
        birth_date DATE NOT NULL,
        gender ENUM('male', 'female', 'other') NOT NULL,
        bio TEXT,
        contract_accepted TINYINT(1) DEFAULT 0,
        login VARCHAR(50) UNIQUE,
        password_hash VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS programming_languages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) UNIQUE NOT NULL
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS application_languages (
        application_id INT,
        language_id INT,
        PRIMARY KEY (application_id, language_id),
        FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
        FOREIGN KEY (language_id) REFERENCES programming_languages(id) ON DELETE CASCADE
    )
");

$languages = ['PHP', 'Python', 'JavaScript', 'Java', 'C++', 'C#', 'Ruby', 'Go', 'Swift', 'Kotlin'];
$stmt = $pdo->prepare("INSERT IGNORE INTO programming_languages (name) VALUES (?)");
foreach ($languages as $lang) {
    $stmt->execute([$lang]);
}

// REST API для админки
if ($_SERVER['REQUEST_METHOD'] == 'PUT' && isset($_GET['id'])) {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input) {
        $user_id = (int)$_GET['id'];
        $errors = [];
        
        if (empty($input['full_name']) || !preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u', $input['full_name'])) {
            $errors['full_name'] = 'ФИО обязательно и может содержать только буквы, пробелы и дефисы.';
        }
        
        if (empty($input['phone']) || !preg_match('/^(\+7|8)?[\s\-]?\(?[0-9]{3}\)?[\s\-]?[0-9]{3}[\s\-]?[0-9]{2}[\s\-]?[0-9]{2}$/', $input['phone'])) {
            $errors['phone'] = 'Введите корректный номер телефона.';
        }
        
        if (empty($input['email']) || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Введите корректный e-mail.';
        }
        
        if (empty($input['birth_date'])) {
            $errors['birth_date'] = 'Выберите дату рождения.';
        }
        
        if (empty($input['gender']) || !in_array($input['gender'], ['male', 'female', 'other'])) {
            $errors['gender'] = 'Выберите пол.';
        }
        
        $selectedLangs = $input['languages'] ?? [];
        if (empty($selectedLangs)) {
            $errors['languages'] = 'Выберите хотя бы один язык.';
        }
        
        if (empty($input['contract'])) {
            $errors['contract'] = 'Необходимо принять условия.';
        }
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    UPDATE applications 
                    SET full_name = ?, phone = ?, email = ?, birth_date = ?, 
                        gender = ?, bio = ?, contract_accepted = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $input['full_name'],
                    $input['phone'],
                    $input['email'],
                    $input['birth_date'],
                    $input['gender'],
                    $input['bio'],
                    1,
                    $user_id
                ]);
                
                $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?")->execute([$user_id]);
                $stmtLang = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
                foreach ($selectedLangs as $langId) {
                    $stmtLang->execute([$user_id, $langId]);
                }
                
                $pdo->commit();
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Данные успешно обновлены']);
                exit();
            } catch(PDOException $e) {
                $pdo->rollBack();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'errors' => ['db_error' => $e->getMessage()]]);
                exit();
            }
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'DELETE' && isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$user_id]);
        $pdo->commit();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Пользователь удален']);
        exit();
    } catch(PDOException $e) {
        $pdo->rollBack();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

// Обычная обработка для не-JS
$message = '';
$message_type = '';
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : null;

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    if (isset($_GET['confirm']) && $_GET['confirm'] == 'yes') {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?")->execute([$delete_id]);
            $pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$delete_id]);
            $pdo->commit();
            $message = "✅ Пользователь успешно удален!";
            $message_type = "success";
        } catch(PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Ошибка при удалении: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

$edit_user_data = null;
if ($edit_id) {
    $stmt = $pdo->prepare("
        SELECT a.*, GROUP_CONCAT(al.language_id) as language_ids
        FROM applications a
        LEFT JOIN application_languages al ON a.id = al.application_id
        WHERE a.id = ?
        GROUP BY a.id
    ");
    $stmt->execute([$edit_id]);
    $edit_user_data = $stmt->fetch();
    
    if ($edit_user_data && $edit_user_data['language_ids']) {
        $edit_user_data['languages'] = explode(',', $edit_user_data['language_ids']);
    } else {
        $edit_user_data['languages'] = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user'])) {
    $user_id = (int)$_POST['user_id'];
    $errors = [];
    
    if (empty($_POST['full_name']) || !preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u', $_POST['full_name'])) {
        $errors['full_name'] = 'ФИО обязательно и может содержать только буквы, пробелы и дефисы.';
    }
    
    if (empty($_POST['phone']) || !preg_match('/^(\+7|8)?[\s\-]?\(?[0-9]{3}\)?[\s\-]?[0-9]{3}[\s\-]?[0-9]{2}[\s\-]?[0-9]{2}$/', $_POST['phone'])) {
        $errors['phone'] = 'Введите корректный номер телефона.';
    }
    
    if (empty($_POST['email']) || !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Введите корректный e-mail.';
    }
    
    if (empty($_POST['birth_date'])) {
        $errors['birth_date'] = 'Выберите дату рождения.';
    }
    
    if (empty($_POST['gender']) || !in_array($_POST['gender'], ['male', 'female', 'other'])) {
        $errors['gender'] = 'Выберите пол.';
    }
    
    $selectedLangs = $_POST['languages'] ?? [];
    if (empty($selectedLangs)) {
        $errors['languages'] = 'Выберите хотя бы один язык.';
    }
    
    if (!isset($_POST['contract'])) {
        $errors['contract'] = 'Необходимо принять условия.';
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                UPDATE applications 
                SET full_name = ?, phone = ?, email = ?, birth_date = ?, 
                    gender = ?, bio = ?, contract_accepted = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['full_name'],
                $_POST['phone'],
                $_POST['email'],
                $_POST['birth_date'],
                $_POST['gender'],
                $_POST['bio'],
                1,
                $user_id
            ]);
            
            $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?")->execute([$user_id]);
            $stmtLang = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($selectedLangs as $langId) {
                $stmtLang->execute([$user_id, $langId]);
            }
            
            $pdo->commit();
            $message = "✅ Данные пользователя успешно обновлены!";
            $message_type = "success";
            
            header("Location: admin.php?message=" . urlencode($message));
            exit();
        } catch(PDOException $e) {
            $pdo->rollBack();
            $message = "❌ Ошибка при обновлении: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $message_type = "success";
}

$languagesList = $pdo->query("SELECT id, name FROM programming_languages ORDER BY name")->fetchAll();

$users = $pdo->query("
    SELECT a.*, 
           GROUP_CONCAT(pl.name SEPARATOR ', ') as languages_names
    FROM applications a
    LEFT JOIN application_languages al ON a.id = al.application_id
    LEFT JOIN programming_languages pl ON al.language_id = pl.id
    GROUP BY a.id
    ORDER BY a.id DESC
")->fetchAll();

$stats = $pdo->query("
    SELECT pl.id, pl.name, COUNT(al.application_id) as count
    FROM programming_languages pl
    LEFT JOIN application_languages al ON pl.id = al.language_id
    GROUP BY pl.id, pl.name
    ORDER BY count DESC
")->fetchAll();

$total_users = count($users);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель администратора - AutoElite</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #800020;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .admin-header {
            background: #9E9E9E;
            color: #800020;
            padding: 20px 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
        }

        .admin-header h1 {
            font-size: 1.8em;
        }

        .admin-info {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .logout-btn {
            background: rgba(128, 0, 32, 0.8);
            color: white;
            padding: 8px 20px;
            border-radius: 10px;
            text-decoration: none;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            background: #800020;
            transform: translateY(-2px);
        }

        .message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .section h2 {
            color: #800020;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #9E9E9E;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .stat-card {
            background: linear-gradient(135deg, #9E9E9E 0%, #757575 100%);
            color: #800020;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card h3 {
            color: #800020;
            margin-bottom: 10px;
            opacity: 0.9;
        }

        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            color: #800020;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        th {
            background: #f8f9fa;
            color: #800020;
            font-weight: 600;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-edit, .btn-delete {
            padding: 5px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9em;
            transition: all 0.3s;
            display: inline-block;
        }

        .btn-edit {
            background: #4CAF50;
            color: white;
        }

        .btn-edit:hover {
            background: #45a049;
        }

        .btn-delete {
            background: #f44336;
            color: white;
        }

        .btn-delete:hover {
            background: #da190b;
        }

        .modal {
            display: <?= $edit_user_data ? 'flex' : 'none' ?>;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 30px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #9E9E9E;
        }

        .modal-header h3 {
            color: #800020;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: #999;
        }

        .close-btn:hover {
            color: #800020;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1em;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #9E9E9E;
            box-shadow: 0 0 0 3px rgba(158, 158, 158, 0.3);
        }

        select[multiple] {
            height: 120px;
        }

        .btn-submit {
            background: #9E9E9E;
            color: #800020;
            border: none;
            padding: 12px 30px;
            font-size: 1em;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-submit:hover {
            background: #757575;
            transform: translateY(-2px);
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #800020;
            text-decoration: none;
            font-weight: 600;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            th, td {
                padding: 8px;
                font-size: 0.85em;
            }
            
            .admin-header {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="admin-header">
            <h1>👑 Панель администратора AutoElite</h1>
            <div class="admin-info">
                <span>👤 <?= htmlspecialchars($admin_login) ?></span>
                <a href="#" onclick="logout()" class="logout-btn">🚪 Выйти</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message <?= $message_type ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="section">
            <h2>📊 Статистика по языкам программирования</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Всего пользователей</h3>
                    <div class="stat-number"><?= $total_users ?></div>
                </div>
                <?php foreach ($stats as $stat): ?>
                    <div class="stat-card">
                        <h3><?= htmlspecialchars($stat['name']) ?></h3>
                        <div class="stat-number"><?= $stat['count'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="section">
            <h2>👥 Все пользователи</h2>
            <?php if (empty($users)): ?>
                <p style="text-align: center; color: #999; padding: 40px;">Нет зарегистрированных пользователей</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>ФИО</th>
                                <th>Телефон</th>
                                <th>Email</th>
                                <th>Дата рождения</th>
                                <th>Пол</th>
                                <th>Языки</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?= $user['id'] ?></td>
                                    <td><?= htmlspecialchars($user['full_name']) ?></td>
                                    <td><?= htmlspecialchars($user['phone']) ?></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td><?= htmlspecialchars($user['birth_date']) ?></td>
                                    <td>
                                        <?php
                                        $genders = ['male' => 'Мужской', 'female' => 'Женский', 'other' => 'Другой'];
                                        echo $genders[$user['gender']] ?? $user['gender'];
                                        ?>
                                    </td>
                                    <td><?= htmlspecialchars($user['languages_names'] ?: '-') ?></td>
                                    <td class="actions">
                                        <a href="?edit=<?= $user['id'] ?>" class="btn-edit">✏️ Редакт.</a>
                                        <a href="#" class="btn-delete" onclick="deleteUser(event, <?= $user['id'] ?>, '<?= htmlspecialchars(addslashes($user['full_name'])) ?>')">🗑️ Удалить</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <a href="index.php" class="back-link">← Вернуться на сайт</a>
        </div>
    </div>

    <?php if ($edit_user_data && $languagesList): ?>
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>✏️ Редактирование пользователя #<?= $edit_user_data['id'] ?></h3>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="user_id" value="<?= $edit_user_data['id'] ?>">
                
                <div class="form-group">
                    <label>ФИО *</label>
                    <input type="text" name="full_name" id="edit_full_name" value="<?= htmlspecialchars($edit_user_data['full_name']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Телефон *</label>
                    <input type="tel" name="phone" id="edit_phone" value="<?= htmlspecialchars($edit_user_data['phone']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" id="edit_email" value="<?= htmlspecialchars($edit_user_data['email']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Дата рождения *</label>
                    <input type="date" name="birth_date" id="edit_birth_date" value="<?= htmlspecialchars($edit_user_data['birth_date']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Пол *</label>
                    <select name="gender" id="edit_gender" required>
                        <option value="male" <?= $edit_user_data['gender'] == 'male' ? 'selected' : '' ?>>Мужской</option>
                        <option value="female" <?= $edit_user_data['gender'] == 'female' ? 'selected' : '' ?>>Женский</option>
                        <option value="other" <?= $edit_user_data['gender'] == 'other' ? 'selected' : '' ?>>Другой</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Любимые языки *</label>
                    <select name="languages[]" id="edit_languages" multiple required>
                        <?php foreach ($languagesList as $lang): ?>
                            <option value="<?= $lang['id'] ?>" <?= in_array($lang['id'], $edit_user_data['languages']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lang['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Биография</label>
                    <textarea name="bio" id="edit_bio" rows="4"><?= htmlspecialchars($edit_user_data['bio']) ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="contract" value="1" <?= $edit_user_data['contract_accepted'] ? 'checked' : '' ?> required>
                        Согласие на обработку данных *
                    </label>
                </div>
                
                <button type="submit" name="update_user" class="btn-submit">💾 Сохранить изменения</button>
            </form>
        </div>
    </div>
    
    <script>
        function closeModal() {
            window.location.href = 'admin.php';
        }
        
        document.getElementById('editModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
    <?php endif; ?>

    <script>
        function deleteUser(event, userId, userName) {
            event.preventDefault();
            if (confirm(`Вы уверены, что хотите удалить пользователя "${userName}"? Это действие нельзя отменить.`)) {
                // AJAX DELETE для JS
                fetch(`admin.php?id=${userId}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert(result.message);
                        window.location.reload();
                    } else {
                        alert('Ошибка: ' + result.message);
                    }
                })
                .catch(error => {
                    // Fallback на обычную ссылку
                    window.location.href = `?delete=${userId}&confirm=yes`;
                });
            }
            return false;
        }
        
        function logout() {
            if (confirm('Выйти из админ-панели?')) {
                fetch(window.location.href, {
                    headers: {
                        'Authorization': 'Basic ' + btoa('logout:logout')
                    }
                }).then(() => {
                    window.location.href = window.location.href;
                }).catch(() => {
                    window.location.reload();
                });
            }
        }
        
        // AJAX редактирование
        const editForm = document.getElementById('editForm');
        if (editForm) {
            editForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(editForm);
                const data = {};
                for (let [key, value] of formData.entries()) {
                    if (key === 'languages[]') {
                        if (!data['languages']) data['languages'] = [];
                        data['languages'].push(value);
                    } else if (key === 'contract') {
                        data['contract'] = true;
                    } else if (key !== 'update_user' && key !== 'user_id') {
                        data[key] = value;
                    }
                }
                if (!data['languages']) data['languages'] = [];
                if (!data['contract']) data['contract'] = false;
                
                const userId = formData.get('user_id');
                
                try {
                    const response = await fetch(`admin.php?id=${userId}`, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(data)
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        alert(result.message);
                        window.location.href = 'admin.php';
                    } else if (result.errors) {
                        let errorMsg = 'Ошибки валидации:\n';
                        for (const [field, msg] of Object.entries(result.errors)) {
                            errorMsg += `- ${msg}\n`;
                        }
                        alert(errorMsg);
                    }
                } catch (error) {
                    editForm.submit();
                }
            });
        }
    </script>
</body>
</html>
