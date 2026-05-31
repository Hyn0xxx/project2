<?php
// HTTP Basic Authentication
$admin_login = 'admin';
$admin_password = 'admin123';

if (!isset($_SERVER['PHP_AUTH_USER']) || 
    $_SERVER['PHP_AUTH_USER'] != $admin_login || 
    $_SERVER['PHP_AUTH_PW'] != $admin_password) {
    
    header('HTTP/1.1 401 Unauthorized');
    header('WWW-Authenticate: Basic realm="Admin Panel"');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Доступ запрещен</title><style>body{font-family:Arial;background:#800020;display:flex;justify-content:center;align-items:center;height:100vh;}.error-box{background:white;padding:40px;border-radius:20px;text-align:center;}</style></head><body><div class="error-box"><h1>🔒 Доступ запрещен</h1><p>Требуется авторизация</p><button onclick="location.reload()">Попробовать снова</button></div></body></html>';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');

// Подключение к БД
$db_user = 'u82464';
$db_pass = '8104996';
$db_name = 'u82464';
$db_host = 'localhost';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch(PDOException $e) {
    die('Ошибка подключения к базе данных: ' . $e->getMessage());
}

// Создаем таблицы если нет
try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cars (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) UNIQUE NOT NULL,
        brand VARCHAR(50),
        price_min INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS applications (
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
    ) ENGINE=InnoDB");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS application_cars (
        application_id INT NOT NULL,
        car_id INT NOT NULL,
        PRIMARY KEY (application_id, car_id),
        FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
        FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    
    // Добавляем авто если пусто
    $check = $pdo->query("SELECT COUNT(*) as cnt FROM cars")->fetch();
    if ($check['cnt'] == 0) {
        $cars = [
            ['Porsche Panamera', 'Porsche', 9500000],
            ['Mercedes-Benz S-Class', 'Mercedes-Benz', 12000000],
            ['BMW 7 Series', 'BMW', 8900000],
            ['Audi A8', 'Audi', 8500000],
            ['Lexus LS', 'Lexus', 9200000],
            ['Range Rover', 'Land Rover', 11000000],
            ['Bentley Continental', 'Bentley', 18000000],
            ['Ferrari Roma', 'Ferrari', 22000000]
        ];
        $stmt = $pdo->prepare("INSERT INTO cars (name, brand, price_min) VALUES (?, ?, ?)");
        foreach ($cars as $car) $stmt->execute($car);
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
} catch(PDOException $e) {
    error_log('Table error: ' . $e->getMessage());
}

// Получаем список автомобилей
$carsList = $pdo->query("SELECT id, name, brand, price_min FROM cars ORDER BY name")->fetchAll();

// Обработка удаления
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && isset($_GET['confirm'])) {
    $delete_id = (int)$_GET['delete'];
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM application_cars WHERE application_id = ?")->execute([$delete_id]);
        $pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$delete_id]);
        $pdo->commit();
        $message = "✅ Пользователь успешно удален!";
    } catch(PDOException $e) {
        $pdo->rollBack();
        $message = "❌ Ошибка: " . $e->getMessage();
    }
}

// Обработка редактирования
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$edit_user_data = null;

if ($edit_id && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user'])) {
    $user_id = (int)$_POST['user_id'];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE applications SET full_name=?, phone=?, email=?, birth_date=?, gender=?, bio=?, contract_accepted=? WHERE id=?");
        $stmt->execute([$_POST['full_name'], $_POST['phone'], $_POST['email'], $_POST['birth_date'], $_POST['gender'], $_POST['bio'], 1, $user_id]);
        
        $pdo->prepare("DELETE FROM application_cars WHERE application_id=?")->execute([$user_id]);
        $stmtCar = $pdo->prepare("INSERT INTO application_cars (application_id, car_id) VALUES (?, ?)");
        foreach ($_POST['cars'] ?? [] as $carId) {
            $stmtCar->execute([$user_id, $carId]);
        }
        
        $pdo->commit();
        $message = "✅ Данные обновлены!";
        header("Location: admin.php");
        exit();
    } catch(PDOException $e) {
        $pdo->rollBack();
        $message = "❌ Ошибка: " . $e->getMessage();
    }
}

if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM applications WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_user_data = $stmt->fetch();
    if ($edit_user_data) {
        $stmt = $pdo->prepare("SELECT car_id FROM application_cars WHERE application_id = ?");
        $stmt->execute([$edit_id]);
        $edit_user_data['cars'] = array_column($stmt->fetchAll(), 'car_id');
    }
}

// Получение данных для таблицы
$users = $pdo->query("
    SELECT a.*, GROUP_CONCAT(c.name SEPARATOR ', ') as cars_names
    FROM applications a
    LEFT JOIN application_cars ac ON a.id = ac.application_id
    LEFT JOIN cars c ON ac.car_id = c.id
    GROUP BY a.id
    ORDER BY a.id DESC
")->fetchAll();

$stats = $pdo->query("
    SELECT c.id, c.name, COUNT(ac.application_id) as count
    FROM cars c
    LEFT JOIN application_cars ac ON c.id = ac.car_id
    GROUP BY c.id
    ORDER BY count DESC
")->fetchAll();

$total_users = count($users);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель администратора AutoElite</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Segoe UI',sans-serif;background:#800020;padding:20px;}
        .container{max-width:1400px;margin:0 auto;}
        .admin-header{background:#9E9E9E;color:#800020;padding:20px;border-radius:20px;margin-bottom:30px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;}
        .admin-header h1{font-size:1.8em;}
        .logout-btn{background:#800020;color:white;padding:8px 20px;border-radius:10px;text-decoration:none;}
        .message{padding:15px;border-radius:10px;margin-bottom:20px;background:#d4edda;color:#155724;}
        .section{background:white;border-radius:20px;padding:25px;margin-bottom:30px;box-shadow:0 5px 20px rgba(0,0,0,0.1);}
        .section h2{color:#800020;margin-bottom:20px;border-bottom:2px solid #9E9E9E;padding-bottom:10px;}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;}
        .stat-card{background:linear-gradient(135deg,#9E9E9E,#757575);padding:20px;border-radius:15px;text-align:center;color:#800020;}
        .stat-card h3{color:#800020;margin-bottom:10px;}
        .stat-number{font-size:2.5em;font-weight:bold;}
        .table-wrapper{overflow-x:auto;}
        table{width:100%;border-collapse:collapse;}
        th,td{padding:12px;text-align:left;border-bottom:1px solid #e0e0e0;}
        th{background:#f8f9fa;color:#800020;}
        tr:hover{background:#f8f9fa;}
        .btn-edit{background:#4CAF50;color:white;padding:5px 12px;border-radius:5px;text-decoration:none;display:inline-block;margin-right:5px;}
        .btn-delete{background:#f44336;color:white;padding:5px 12px;border-radius:5px;text-decoration:none;display:inline-block;}
        .modal{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;justify-content:center;align-items:center;z-index:1000;}
        .modal-content{background:white;border-radius:20px;width:90%;max-width:600px;max-height:90vh;overflow-y:auto;padding:30px;}
        .modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border-bottom:2px solid #9E9E9E;padding-bottom:10px;}
        .modal-header h3{color:#800020;}
        .close-btn{background:none;border:none;font-size:1.5em;cursor:pointer;color:#999;}
        .form-group{margin-bottom:20px;}
        .form-group label{display:block;margin-bottom:8px;font-weight:600;}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:10px;border:2px solid #e0e0e0;border-radius:10px;}
        select[multiple]{height:120px;}
        .btn-submit{background:#9E9E9E;color:#800020;border:none;padding:12px 30px;border-radius:10px;cursor:pointer;font-weight:600;}
        .btn-submit:hover{background:#757575;}
        @media(max-width:768px){th,td{padding:8px;font-size:0.85em;}}
    </style>
</head>
<body>
    <div class="container">
        <div class="admin-header">
            <h1>👑 Панель администратора AutoElite</h1>
            <div><span>👤 <?= htmlspecialchars($admin_login) ?></span> <a href="#" onclick="logout()" class="logout-btn">🚪 Выйти</a></div>
        </div>

        <?php if (isset($message)): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="section">
            <h2>📊 Статистика по автомобилям</h2>
            <div class="stats-grid">
                <div class="stat-card"><h3>Всего заявок</h3><div class="stat-number"><?= $total_users ?></div></div>
                <?php foreach ($stats as $stat): ?>
                    <div class="stat-card"><h3><?= htmlspecialchars($stat['name']) ?></h3><div class="stat-number"><?= $stat['count'] ?></div></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="section">
            <h2>👥 Все заявки клиентов</h2>
            <?php if (empty($users)): ?>
                <p style="text-align:center;color:#999;padding:40px;">Нет зарегистрированных пользователей</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr><th>ID</th><th>ФИО</th><th>Телефон</th><th>Email</th><th>Дата рождения</th><th>Пол</th><th>Автомобили</th><th>Действия</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= $user['id'] ?></td>
                                <td><?= htmlspecialchars($user['full_name']) ?></td>
                                <td><?= htmlspecialchars($user['phone']) ?></td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td><?= htmlspecialchars($user['birth_date']) ?></td>
                                <td><?= ['male'=>'Мужской','female'=>'Женский','other'=>'Другой'][$user['gender']] ?? $user['gender'] ?></td>
                                <td><?= htmlspecialchars($user['cars_names'] ?: '-') ?></td>
                                <td><a href="?edit=<?= $user['id'] ?>" class="btn-edit">✏️ Редакт.</a> <a href="?delete=<?= $user['id'] ?>" class="btn-delete" onclick="return confirmDelete(event, <?= $user['id'] ?>, '<?= htmlspecialchars(addslashes($user['full_name'])) ?>')">🗑️ Удалить</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($edit_user_data && $carsList): ?>
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header"><h3>✏️ Редактирование #<?= $edit_user_data['id'] ?></h3><button class="close-btn" onclick="closeModal()">&times;</button></div>
            <form method="POST">
                <input type="hidden" name="user_id" value="<?= $edit_user_data['id'] ?>">
                <div class="form-group"><label>ФИО *</label><input type="text" name="full_name" value="<?= htmlspecialchars($edit_user_data['full_name']) ?>" required></div>
                <div class="form-group"><label>Телефон *</label><input type="tel" name="phone" value="<?= htmlspecialchars($edit_user_data['phone']) ?>" required></div>
                <div class="form-group"><label>Email *</label><input type="email" name="email" value="<?= htmlspecialchars($edit_user_data['email']) ?>" required></div>
                <div class="form-group"><label>Дата рождения *</label><input type="date" name="birth_date" value="<?= htmlspecialchars($edit_user_data['birth_date']) ?>" required></div>
                <div class="form-group"><label>Пол *</label><select name="gender" required><option value="male" <?= $edit_user_data['gender']=='male'?'selected':'' ?>>Мужской</option><option value="female" <?= $edit_user_data['gender']=='female'?'selected':'' ?>>Женский</option><option value="other" <?= $edit_user_data['gender']=='other'?'selected':'' ?>>Другой</option></select></div>
                <div class="form-group"><label>Автомобили *</label><select name="cars[]" multiple required><?php foreach ($carsList as $car): ?><option value="<?= $car['id'] ?>" <?= in_array($car['id'], $edit_user_data['cars'] ?? []) ? 'selected' : '' ?>><?= htmlspecialchars($car['brand'] . ' ' . $car['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Пожелания</label><textarea name="bio" rows="4"><?= htmlspecialchars($edit_user_data['bio']) ?></textarea></div>
                <div class="form-group"><label><input type="checkbox" name="contract" value="1" <?= $edit_user_data['contract_accepted'] ? 'checked' : '' ?> required> Согласие на обработку *</label></div>
                <button type="submit" name="update_user" class="btn-submit">💾 Сохранить</button>
            </form>
        </div>
    </div>
    <script>
        function closeModal(){ window.location.href='admin.php'; }
        document.getElementById('editModal')?.addEventListener('click',function(e){if(e.target===this)closeModal();});
    </script>
    <?php endif; ?>

    <script>
        function confirmDelete(event, userId, userName){
            event.preventDefault();
            if(confirm(`Удалить "${userName}"?`)) window.location.href=`?delete=${userId}&confirm=yes`;
            return false;
        }
        function logout(){
            if(confirm('Выйти из админ-панели?')){
                fetch(window.location.href,{headers:{'Authorization':'Basic '+btoa('logout:logout')}}).then(()=>window.location.reload());
            }
        }
    </script>
</body>
</html>
