<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

session_start();

// Подключение к БД
$db_user = 'u82464';
$db_pass = '8104996';
$db_name = 'u82464';
$db_host = 'localhost';

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка БД: ' . $e->getMessage()]);
    exit;
}

function generateLogin() {
    return 'user_' . bin2hex(random_bytes(4));
}

function generatePassword($length = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Неверный формат данных']);
    exit;
}

$action = $_GET['action'] ?? '';

// CREATE - POST для новой анкеты
if ($action === 'create') {
    $errors = [];
    
    // Валидация
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
    
    $selectedCars = $input['languages'] ?? [];
    if (empty($selectedCars)) {
        $errors['languages'] = 'Выберите хотя бы один автомобиль.';
    }
    
    if (!isset($input['contract'])) {
        $errors['contract'] = 'Необходимо принять условия.';
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $login = generateLogin();
        $plainPassword = generatePassword();
        $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("
            INSERT INTO applications (full_name, phone, email, birth_date, gender, bio, contract_accepted, login, password_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $input['full_name'],
            $input['phone'],
            $input['email'],
            $input['birth_date'],
            $input['gender'],
            $input['bio'] ?? '',
            1,
            $login,
            $passwordHash
        ]);
        $appId = $pdo->lastInsertId();
        
        $stmtLang = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
        foreach ($selectedCars as $carId) {
            $stmtLang->execute([$appId, $carId]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'login' => $login,
            'password' => $plainPassword,
            'message' => 'Анкета успешно сохранена!'
        ]);
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Ошибка БД: ' . $e->getMessage()]);
    }
    exit;
}

// UPDATE - PUT для редактирования авторизованного пользователя
if ($action === 'update') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
        exit;
    }
    
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
    
    $selectedCars = $input['languages'] ?? [];
    if (empty($selectedCars)) {
        $errors['languages'] = 'Выберите хотя бы один автомобиль.';
    }
    
    if (!isset($input['contract'])) {
        $errors['contract'] = 'Необходимо принять условия.';
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            UPDATE applications 
            SET full_name = ?, phone = ?, email = ?, birth_date = ?, gender = ?, bio = ?, contract_accepted = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $input['full_name'],
            $input['phone'],
            $input['email'],
            $input['birth_date'],
            $input['gender'],
            $input['bio'] ?? '',
            1,
            $_SESSION['user_id']
        ]);
        
        $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?")->execute([$_SESSION['user_id']]);
        $stmtLang = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
        foreach ($selectedCars as $carId) {
            $stmtLang->execute([$_SESSION['user_id'], $carId]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Данные успешно обновлены!'
        ]);
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Ошибка БД: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Неверное действие']);
