<?php
session_start();

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
// ФУНКЦИИ
// --------------------

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

// Добавляем языки, если их нет
$languages = ['PHP', 'Python', 'JavaScript', 'Java', 'C++', 'C#', 'Ruby', 'Go', 'Swift', 'Kotlin'];
$stmt = $pdo->prepare("INSERT IGNORE INTO programming_languages (name) VALUES (?)");
foreach ($languages as $lang) {
    $stmt->execute([$lang]);
}

// --------------------
// ЯЗЫКИ
// --------------------

$languagesList = $pdo->query("
    SELECT id, name
    FROM programming_languages
    ORDER BY name
")->fetchAll();

$allowedLanguageIds = array_column($languagesList, 'id');

// --------------------
// ВЫХОД
// --------------------

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// --------------------
// АВТОРИЗАЦИЯ
// --------------------

$messages = [];
$loginError = '';
$showLoginForm = !isset($_SESSION['user_id']);

if (isset($_POST['login_submit'])) {
    $login = trim($_POST['login'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($login)) {
        $loginError = 'Введите логин';
    }
    elseif (empty($password)) {
        $loginError = 'Введите пароль';
    }
    else {
        $stmt = $pdo->prepare("SELECT * FROM applications WHERE login = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            header('Location: index.php');
            exit();
        } else {
            $loginError = 'Неверный логин или пароль';
        }
    }
}

// --------------------
// ОБРАБОТКА ОСНОВНОЙ ФОРМЫ
// --------------------

$justSaved = false;
$ajaxResponse = null;

// REST API endpoint
if ($_SERVER['REQUEST_METHOD'] == 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($input) {
        $result = processFormData($input, $pdo, $languagesList, $allowedLanguageIds);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit();
    }
}

function processFormData($data, $pdo, $languagesList, $allowedLanguageIds) {
    $errors = [];
    
    // ФИО
    if (empty($data['full_name']) || !preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u', $data['full_name'])) {
        $errors['full_name'] = 'ФИО обязательно и может содержать только буквы, пробелы и дефисы.';
    }
    
    // ТЕЛЕФОН
    if (empty($data['phone']) || !preg_match('/^(\+7|8)?[\s\-]?\(?[0-9]{3}\)?[\s\-]?[0-9]{3}[\s\-]?[0-9]{2}[\s\-]?[0-9]{2}$/', $data['phone'])) {
        $errors['phone'] = 'Введите корректный номер телефона.';
    }
    
    // EMAIL
    if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Введите корректный e-mail.';
    }
    
    // ДАТА
    if (empty($data['birth_date'])) {
        $errors['birth_date'] = 'Выберите дату рождения.';
    }
    
    // ПОЛ
    if (empty($data['gender']) || !in_array($data['gender'], ['male', 'female', 'other'])) {
        $errors['gender'] = 'Выберите пол.';
    }
    
    // ЯЗЫКИ
    $selectedLangs = $data['languages'] ?? [];
    if (empty($selectedLangs)) {
        $errors['languages'] = 'Выберите хотя бы один язык.';
    }
    foreach ($selectedLangs as $langId) {
        if (!in_array($langId, $allowedLanguageIds)) {
            $errors['languages'] = 'Выбран недопустимый язык.';
        }
    }
    
    // CONTRACT
    if (empty($data['contract'])) {
        $errors['contract'] = 'Необходимо принять условия.';
    }
    
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }
    
    try {
        $pdo->beginTransaction();
        
        $isAuth = isset($_SESSION['user_id']);
        
        if ($isAuth) {
            // UPDATE
            $appId = $_SESSION['user_id'];
            $stmt = $pdo->prepare("
                UPDATE applications
                SET full_name=?, phone=?, email=?, birth_date=?, gender=?, bio=?, contract_accepted=?
                WHERE id=?
            ");
            $stmt->execute([
                $data['full_name'],
                $data['phone'],
                $data['email'],
                $data['birth_date'],
                $data['gender'],
                $data['bio'] ?? '',
                1,
                $appId
            ]);
            
            $pdo->prepare("DELETE FROM application_languages WHERE application_id=?")->execute([$appId]);
            $messages = ['✅ Данные успешно обновлены!'];
        } else {
            // INSERT
            $login = generateLogin();
            $plainPassword = generatePassword();
            $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                INSERT INTO applications (full_name, phone, email, birth_date, gender, bio, contract_accepted, login, password_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['full_name'],
                $data['phone'],
                $data['email'],
                $data['birth_date'],
                $data['gender'],
                $data['bio'] ?? '',
                1,
                $login,
                $passwordHash
            ]);
            $appId = $pdo->lastInsertId();
            
            $messages = ['✅ Данные успешно сохранены!'];
            $credentials = ['login' => $login, 'password' => $plainPassword];
        }
        
        // Сохраняем языки
        $stmtLang = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
        foreach ($selectedLangs as $langId) {
            $stmtLang->execute([$appId, $langId]);
        }
        
        $pdo->commit();
        
        $result = ['success' => true, 'messages' => $messages];
        if (isset($credentials)) {
            $result['credentials'] = $credentials;
        }
        if ($isAuth) {
            $result['updated'] = true;
        }
        
        return $result;
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        return ['success' => false, 'errors' => ['db_error' => 'Ошибка БД: ' . $e->getMessage()]];
    }
}

// Обычная POST обработка (без JS)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['login_submit'])) {
    $errors = [];
    
    // ФИО
    if (empty($_POST['full_name']) || !preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u', $_POST['full_name'])) {
        $errors['full_name'] = 'ФИО обязательно и может содержать только буквы, пробелы и дефисы.';
    }
    
    // ТЕЛЕФОН
    if (empty($_POST['phone']) || !preg_match('/^(\+7|8)?[\s\-]?\(?[0-9]{3}\)?[\s\-]?[0-9]{3}[\s\-]?[0-9]{2}[\s\-]?[0-9]{2}$/', $_POST['phone'])) {
        $errors['phone'] = 'Введите корректный номер телефона.';
    }
    
    // EMAIL
    if (empty($_POST['email']) || !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Введите корректный e-mail.';
    }
    
    // ДАТА
    if (empty($_POST['birth_date'])) {
        $errors['birth_date'] = 'Выберите дату рождения.';
    }
    
    // ПОЛ
    if (empty($_POST['gender']) || !in_array($_POST['gender'], ['male', 'female', 'other'])) {
        $errors['gender'] = 'Выберите пол.';
    }
    
    // ЯЗЫКИ
    $selectedLangs = $_POST['languages'] ?? [];
    if (empty($selectedLangs)) {
        $errors['languages'] = 'Выберите хотя бы один язык.';
    }
    foreach ($selectedLangs as $langId) {
        if (!in_array($langId, $allowedLanguageIds)) {
            $errors['languages'] = 'Выбран недопустимый язык.';
        }
    }
    
    // CONTRACT
    if (!isset($_POST['contract'])) {
        $errors['contract'] = 'Необходимо принять условия.';
    }
    
    // Сохраняем значения для отображения
    $formValues = [
        'full_name' => $_POST['full_name'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'email' => $_POST['email'] ?? '',
        'birth_date' => $_POST['birth_date'] ?? '',
        'gender' => $_POST['gender'] ?? '',
        'bio' => $_POST['bio'] ?? '',
        'contract' => isset($_POST['contract']),
        'languages' => $selectedLangs
    ];
    
    // ЕСЛИ НЕТ ОШИБОК - СОХРАНЯЕМ
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // UPDATE (если пользователь авторизован)
            if (isset($_SESSION['user_id'])) {
                $appId = $_SESSION['user_id'];
                $stmt = $pdo->prepare("
                    UPDATE applications
                    SET full_name=?, phone=?, email=?, birth_date=?, gender=?, bio=?, contract_accepted=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['full_name'],
                    $_POST['phone'],
                    $_POST['email'],
                    $_POST['birth_date'],
                    $_POST['gender'],
                    $_POST['bio'],
                    1,
                    $appId
                ]);
                
                $pdo->prepare("DELETE FROM application_languages WHERE application_id=?")->execute([$appId]);
                $messages[] = '✅ Данные успешно обновлены!';
            } else {
                // INSERT (новая анкета)
                $login = generateLogin();
                $plainPassword = generatePassword();
                $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("
                    INSERT INTO applications (full_name, phone, email, birth_date, gender, bio, contract_accepted, login, password_hash)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['full_name'],
                    $_POST['phone'],
                    $_POST['email'],
                    $_POST['birth_date'],
                    $_POST['gender'],
                    $_POST['bio'],
                    1,
                    $login,
                    $passwordHash
                ]);
                $appId = $pdo->lastInsertId();
                
                $_SESSION['generated_login'] = $login;
                $_SESSION['generated_password'] = $plainPassword;
                $justSaved = true;
                
                $messages[] = '✅ Данные успешно сохранены!';
            }
            
            // Сохраняем языки
            $stmtLang = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($selectedLangs as $langId) {
                $stmtLang->execute([$appId, $langId]);
            }
            
            $pdo->commit();
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $errors['db_error'] = 'Ошибка БД: ' . $e->getMessage();
        }
    }
}

// --------------------
// ЗАГРУЗКА ДАННЫХ ДЛЯ ФОРМЫ
// --------------------

// Если есть отправленные значения из формы (при ошибке)
if (isset($formValues)) {
    $values = $formValues;
    $errors = $errors ?? [];
} 
// Если пользователь авторизован, загружаем его данные из БД
elseif (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM applications WHERE id=?");
    $stmt->execute([$_SESSION['user_id']]);
    $userData = $stmt->fetch();
    
    if ($userData) {
        $values['full_name'] = $userData['full_name'];
        $values['phone'] = $userData['phone'];
        $values['email'] = $userData['email'];
        $values['birth_date'] = $userData['birth_date'];
        $values['gender'] = $userData['gender'];
        $values['bio'] = $userData['bio'];
        $values['contract'] = $userData['contract_accepted'];
        
        $stmt = $pdo->prepare("SELECT language_id FROM application_languages WHERE application_id=?");
        $stmt->execute([$_SESSION['user_id']]);
        $values['languages'] = array_column($stmt->fetchAll(), 'language_id');
    }
} 
// Пустая форма для нового пользователя
else {
    $values = [
        'full_name' => '',
        'phone' => '',
        'email' => '',
        'birth_date' => '',
        'gender' => '',
        'bio' => '',
        'contract' => false,
        'languages' => []
    ];
    $errors = [];
}

// Добавляем сообщение с логином и паролем
if (!empty($_SESSION['generated_login']) && $justSaved) {
    $loginMessage = "✅ Ваши данные для входа:<br><br>
        Логин: <b>" . htmlspecialchars($_SESSION['generated_login']) . "</b><br>
        Пароль: <b>" . htmlspecialchars($_SESSION['generated_password']) . "</b><br><br>
        ⚠️ Сохраните их! Теперь вы можете авторизоваться и редактировать свои данные.";
    $messages[] = $loginMessage;
    unset($_SESSION['generated_login']);
    unset($_SESSION['generated_password']);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoElite - Премиальный автосалон</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Дополнительные стили для формы анкеты */
        .developer-form-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 80px 0;
        }
        
        .developer-form-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .developer-form-header {
            background: #800020;
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .developer-form-header h2 {
            font-size: 1.8em;
            margin-bottom: 10px;
        }
        
        .developer-form-body {
            padding: 40px;
        }
        
        .auth-section-dev {
            background: #9E9E9E;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
        }
        
        .auth-section-dev h3 {
            color: #800020;
            margin-bottom: 20px;
        }
        
        .form-group-dev {
            margin-bottom: 20px;
        }
        
        .form-group-dev label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group-dev input,
        .form-group-dev select,
        .form-group-dev textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1em;
            transition: all 0.3s;
        }
        
        .form-group-dev input:focus,
        .form-group-dev select:focus,
        .form-group-dev textarea:focus {
            outline: none;
            border-color: #9E9E9E;
            box-shadow: 0 0 0 3px rgba(158,158,158,0.3);
        }
        
        .form-group-dev select[multiple] {
            height: 120px;
        }
        
        .radio-group-dev {
            display: flex;
            gap: 20px;
            padding: 10px 0;
        }
        
        .radio-group-dev label {
            display: inline-flex;
            align-items: center;
            font-weight: normal;
            margin-bottom: 0;
            cursor: pointer;
        }
        
        .radio-group-dev input {
            width: auto;
            margin-right: 8px;
        }
        
        .checkbox-label-dev {
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        
        .checkbox-label-dev input {
            width: auto;
            margin-right: 10px;
        }
        
        .btn-submit-dev {
            background: #800020;
            color: white;
            border: none;
            padding: 14px 30px;
            font-size: 1em;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
        }
        
        .btn-submit-dev:hover {
            background: #9E9E9E;
            color: #800020;
            transform: translateY(-2px);
        }
        
        .error-message-dev {
            color: #dc3545;
            font-size: 0.85em;
            margin-top: 5px;
            display: block;
        }
        
        .success-message-dev {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        
        .form-error-dev {
            border-color: #dc3545 !important;
        }
        
        .logout-link-dev {
            display: inline-block;
            margin-top: 10px;
            color: #800020;
            text-decoration: none;
            font-weight: 600;
        }
        
        .logout-link-dev:hover {
            text-decoration: underline;
        }
        
        hr {
            margin: 30px 0;
            border: none;
            height: 1px;
            background: linear-gradient(to right, transparent, #800020, transparent);
        }
        
        @media (max-width: 768px) {
            .developer-form-body {
                padding: 20px;
            }
            
            .radio-group-dev {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Шапка с видео -->
    <header class="header">
        <div class="video-background">
            <video autoplay muted loop playsinline>
                <source src="assets/video/large-vecteezy_selective-focus-on-a-car-male-customer-talking-to-auto_33116350_x-large.mp4" type="video/mp4">
                Ваш браузер не поддерживает видео.
            </video>
            <div class="video-overlay"></div>
        </div>
        
        <!-- Навигация -->
        <nav class="navbar">
            <div class="container nav-container">
                <div class="logo">
                    <h1>AutoElite</h1>
                    <p>Премиальный автосалон с 2010 года</p>
                </div>
                
                <ul class="nav-menu">
                    <li><a href="#home">Главная</a></li>
                    <li class="dropdown">
                        <a href="#catalog">Каталог <i class="fas fa-chevron-down"></i></a>
                        <ul class="dropdown-menu">
                            <li><a href="#porsche">Porsche</a></li>
                            <li><a href="#mercedes">Mercedes-Benz</a></li>
                            <li><a href="#bmw">BMW</a></li>
                            <li><a href="#audi">Audi</a></li>
                            <li><a href="#lexus">Lexus</a></li>
                        </ul>
                    </li>
                    <li><a href="#services">Услуги</a></li>
                    <li><a href="#about">О нас</a></li>
                    <li><a href="#contacts">Контакты</a></li>
                    <li><a href="#developer-form" class="btn-contact">Стать разработчиком</a></li>
                </ul>
                
                <div class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </div>
            </div>
        </nav>
        
        <div class="mobile-menu" id="mobileMenu">
            <div class="mobile-menu-header">
                <h2>AutoElite</h2>
                <button class="close-menu" id="closeMenuBtn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <ul class="mobile-nav">
                <li><a href="#home">Главная</a></li>
                <li><a href="#catalog">Каталог</a></li>
                <li><a href="#services">Услуги</a></li>
                <li><a href="#about">О нас</a></li>
                <li><a href="#contacts">Контакты</a></li>
                <li><a href="#developer-form">Стать разработчиком</a></li>
            </ul>
        </div>
        
        <div class="hero">
            <div class="container">
                <h2>Эксклюзивные автомобили премиум-класса</h2>
                <p>Подберем идеальный автомобиль по вашим требованиям</p>
                <a href="#catalog" class="btn-hero">Смотреть каталог</a>
            </div>
        </div>
    </header>

    <main>
        <section class="popular-models" id="catalog">
            <div class="container">
                <h2 class="section-title">Популярные модели</h2>
                <p class="section-subtitle">Автомобили, которые выбирают наши клиенты</p>
                
                <div class="slider-container">
                    <div class="slider">
                        <div class="slide active">
                            <div class="slide-image">
                                <img src="https://i.pinimg.com/1200x/b9/ea/01/b9ea017e55f040aea05079c907258b35.jpg" alt="Porsche Panamera" class="car-image">
                                <div class="car-model-badge">Porsche</div>
                            </div>
                            <div class="slide-content">
                                <h3>Porsche Panamera</h3>
                                <p class="slide-description">Спортивная элегантность и мощность</p>
                                <p class="slide-price">от 9 500 000 ₽</p>
                                <a href="#developer-form" class="btn-order">Заказать</a>
                            </div>
                        </div>
                        
                        <div class="slide">
                            <div class="slide-image">
                                <img src="https://i.pinimg.com/1200x/5d/74/97/5d749788759bc112b30f99158c4a2b87.jpg" alt="Mercedes-Benz S-Class" class="car-image">
                                <div class="car-model-badge">Mercedes-Benz</div>
                            </div>
                            <div class="slide-content">
                                <h3>Mercedes-Benz S-Class</h3>
                                <p class="slide-description">Роскошь и инновации</p>
                                <p class="slide-price">от 12 000 000 ₽</p>
                                <a href="#developer-form" class="btn-order">Заказать</a>
                            </div>
                        </div>
                        
                        <div class="slide">
                            <div class="slide-image">
                                <img src="https://i.pinimg.com/1200x/20/cc/3b/20cc3b1b0ec4220d4d4e35e73de480c9.jpg" alt="BMW 7 Series" class="car-image">
                                <div class="car-model-badge">BMW</div>
                            </div>
                            <div class="slide-content">
                                <h3>BMW 7 Series</h3>
                                <p class="slide-description">Динамика и комфорт</p>
                                <p class="slide-price">от 8 900 000 ₽</p>
                                <a href="#developer-form" class="btn-order">Заказать</a>
                            </div>
                        </div>
                    </div>
                    
                    <button class="slider-btn prev-btn" id="prevBtn">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <button class="slider-btn next-btn" id="nextBtn">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                    
                    <div class="slider-indicators">
                        <span class="indicator active" data-slide="0"></span>
                        <span class="indicator" data-slide="1"></span>
                        <span class="indicator" data-slide="2"></span>
                    </div>
                </div>
            </div>
        </section>

        <section class="services" id="services">
            <div class="container">
                <h2 class="section-title">Наши услуги</h2>
                <p class="section-subtitle">Полный комплекс услуг для вашего комфорта</p>
                
                <div class="services-grid">
                    <div class="service-card">
                        <i class="fas fa-car service-icon"></i>
                        <h3>Продажа новых авто</h3>
                        <p>Широкий выбор новых автомобилей премиум-класса от официальных дилеров</p>
                    </div>
                    
                    <div class="service-card">
                        <i class="fas fa-credit-card service-icon"></i>
                        <h3>Кредитование</h3>
                        <p>Выгодные программы кредитования и лизинга от партнерских банков</p>
                    </div>
                    
                    <div class="service-card">
                        <i class="fas fa-exchange-alt service-icon"></i>
                        <h3>Трейд-ин</h3>
                        <p>Выгодный обмен вашего автомобиля на новую модель с доплатой</p>
                    </div>
                    
                    <div class="service-card">
                        <i class="fas fa-search service-icon"></i>
                        <h3>Поиск авто</h3>
                        <p>Поиск и доставка автомобилей по индивидуальным требованиям</p>
                    </div>
                    
                    <div class="service-card">
                        <i class="fas fa-tools service-icon"></i>
                        <h3>Сервисное обслуживание</h3>
                        <p>Полное ТО и ремонт автомобилей в собственном сервисном центре</p>
                    </div>
                    
                    <div class="service-card">
                        <i class="fas fa-spray-can service-icon"></i>
                        <h3>Детейлинг</h3>
                        <p>Премиум-уход за автомобилем: химчистка, полировка, защитные покрытия</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Форма для разработчиков (анкета) -->
        <section class="developer-form-section" id="developer-form">
            <div class="container">
                <div class="developer-form-container">
                    <div class="developer-form-header">
                        <h2>📝 Анкета разработчика</h2>
                        <p>Заполните форму, чтобы стать частью нашей команды</p>
                    </div>
                    
                    <div class="developer-form-body">
                        <div id="ajaxMessages"></div>
                        
                        <?php foreach($messages as $m): ?>
                            <div class="success-message-dev"><?= $m ?></div>
                        <?php endforeach; ?>
                        <?php if (!empty($errors['db_error'])): ?>
                            <div class="success-message-dev" style="background:#f8d7da; color:#721c24;"><?= $errors['db_error'] ?></div>
                        <?php endif; ?>
                        
                        <!-- АВТОРИЗАЦИЯ -->
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <div class="auth-section-dev" id="authSection">
                                <h3>🔐 Авторизация для редактирования</h3>
                                <?php if (!empty($loginError)): ?>
                                    <div class="error-message-dev"><?= $loginError ?></div>
                                <?php endif; ?>
                                
                                <form method="POST" id="loginForm">
                                    <div class="form-group-dev">
                                        <label>Логин</label>
                                        <input type="text" name="login" id="loginInput" class="<?= !empty($loginError) ? 'form-error-dev' : '' ?>">
                                    </div>
                                    
                                    <div class="form-group-dev">
                                        <label>Пароль</label>
                                        <input type="password" name="password" id="passwordInput" class="<?= !empty($loginError) ? 'form-error-dev' : '' ?>">
                                    </div>
                                    
                                    <button type="submit" name="login_submit" class="btn-submit-dev">Войти</button>
                                </form>
                            </div>
                            <hr>
                        <?php else: ?>
                            <div class="success-message-dev" id="userInfo">
                                ✅ Вы авторизованы как <?= htmlspecialchars($values['full_name']) ?>
                                <a href="?logout=1" class="logout-link-dev">Выйти</a>
                            </div>
                        <?php endif; ?>
                        
                        <!-- ОСНОВНАЯ ФОРМА -->
                        <form method="POST" id="developerForm">
                            <div class="form-group-dev">
                                <label>ФИО *</label>
                                <input type="text" name="full_name" id="full_name" value="<?= htmlspecialchars($values['full_name'] ?? '') ?>" class="<?= isset($errors['full_name']) ? 'form-error-dev' : '' ?>">
                                <div class="error-message-dev" id="full_name_error"><?= $errors['full_name'] ?? '' ?></div>
                            </div>
                            
                            <div class="form-group-dev">
                                <label>Телефон *</label>
                                <input type="tel" name="phone" id="phone" value="<?= htmlspecialchars($values['phone'] ?? '') ?>" class="<?= isset($errors['phone']) ? 'form-error-dev' : '' ?>">
                                <div class="error-message-dev" id="phone_error"><?= $errors['phone'] ?? '' ?></div>
                            </div>
                            
                            <div class="form-group-dev">
                                <label>E-mail *</label>
                                <input type="email" name="email" id="email" value="<?= htmlspecialchars($values['email'] ?? '') ?>" class="<?= isset($errors['email']) ? 'form-error-dev' : '' ?>">
                                <div class="error-message-dev" id="email_error"><?= $errors['email'] ?? '' ?></div>
                            </div>
                            
                            <div class="form-group-dev">
                                <label>Дата рождения *</label>
                                <input type="date" name="birth_date" id="birth_date" value="<?= htmlspecialchars($values['birth_date'] ?? '') ?>" class="<?= isset($errors['birth_date']) ? 'form-error-dev' : '' ?>">
                                <div class="error-message-dev" id="birth_date_error"><?= $errors['birth_date'] ?? '' ?></div>
                            </div>
                            
                            <div class="form-group-dev">
                                <label>Пол *</label>
                                <div class="radio-group-dev">
                                    <label>
                                        <input type="radio" name="gender" value="male" <?= (($values['gender'] ?? '') == 'male') ? 'checked' : '' ?>> Мужской
                                    </label>
                                    <label>
                                        <input type="radio" name="gender" value="female" <?= (($values['gender'] ?? '') == 'female') ? 'checked' : '' ?>> Женский
                                    </label>
                                    <label>
                                        <input type="radio" name="gender" value="other" <?= (($values['gender'] ?? '') == 'other') ? 'checked' : '' ?>> Другой
                                    </label>
                                </div>
                                <div class="error-message-dev" id="gender_error"><?= $errors['gender'] ?? '' ?></div>
                            </div>
                            
                            <div class="form-group-dev">
                                <label>Любимые языки программирования *</label>
                                <select name="languages[]" id="languages" multiple>
                                    <?php foreach ($languagesList as $lang): ?>
                                        <option value="<?= $lang['id'] ?>" <?= in_array($lang['id'], $values['languages'] ?? []) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($lang['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="error-message-dev" id="languages_error"><?= $errors['languages'] ?? '' ?></div>
                            </div>
                            
                            <div class="form-group-dev">
                                <label>Биография</label>
                                <textarea name="bio" id="bio"><?= htmlspecialchars($values['bio'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="form-group-dev">
                                <label class="checkbox-label-dev">
                                    <input type="checkbox" name="contract" value="1" id="contract" <?= !empty($values['contract']) ? 'checked' : '' ?>>
                                    Я согласен с условиями обработки данных *
                                </label>
                                <div class="error-message-dev" id="contract_error"><?= $errors['contract'] ?? '' ?></div>
                            </div>
                            
                            <button type="submit" class="btn-submit-dev" id="formSubmitBtn">
                                <?= isset($_SESSION['user_id']) ? '✏️ Обновить анкету' : '✉️ Отправить анкету' ?>
                            </button>
                        </form>
                        
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <p style="margin-top: 20px; text-align: center; color: #666; font-size: 0.85em;">
                                * После отправки анкеты вы получите логин и пароль для редактирования данных
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="contact-form-section" id="form">
            <div class="container">
                <h2 class="section-title">Оставить заявку</h2>
                <p class="section-subtitle">Мы перезвоним вам в течение 15 минут</p>
                
                <form id="contactForm" class="contact-form">
                    <div class="form-group">
                        <label for="contactName">Имя *</label>
                        <input type="text" id="contactName" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="contactPhone">Телефон *</label>
                        <input type="tel" id="contactPhone" name="phone" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="contactEmail">Email *</label>
                        <input type="email" id="contactEmail" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="contactCar">Интересующий автомобиль</label>
                        <select id="contactCar" name="car">
                            <option value="">Выберите модель</option>
                            <option value="porsche-panamera">Porsche Panamera</option>
                            <option value="mercedes-s-class">Mercedes-Benz S-Class</option>
                            <option value="bmw-7-series">BMW 7 Series</option>
                            <option value="audi-a8">Audi A8</option>
                            <option value="lexus-ls">Lexus LS</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="contactMessage">Сообщение</label>
                        <textarea id="contactMessage" name="message" placeholder="Ваши пожелания и вопросы..."></textarea>
                    </div>
                    
                    <div class="form-checkbox">
                        <input type="checkbox" id="contactPrivacy" name="privacy" required>
                        <label for="contactPrivacy">Согласен на обработку персональных данных</label>
                    </div>
                    
                    <button type="submit" class="btn-submit" id="contactSubmitBtn">
                        <span>Отправить заявку</span>
                        <div class="spinner hidden" id="contactSpinner"></div>
                    </button>
                    
                    <div id="contactFormMessage" class="form-message hidden"></div>
                </form>
            </div>
        </section>

        <footer class="footer" id="contacts">
            <div class="container">
                <div class="footer-content">
                    <div class="footer-info">
                        <h3>AutoElite</h3>
                        <p>Премиальный автосалон с 2010 года</p>
                        <p>Москва, ул. Автозаводская, 25</p>
                        <p>+7 (495) 123-45-67</p>
                        <p>info@autoelite.ru</p>
                    </div>
                    
                    <div class="footer-hours">
                        <h4>Часы работы</h4>
                        <p>Пн-Пт: 9:00 - 21:00</p>
                        <p>Сб: 10:00 - 20:00</p>
                        <p>Вс: 10:00 - 18:00</p>
                    </div>
                    
                    <div class="footer-social">
                        <h4>Мы в соцсетях</h4>
                        <div class="social-icons">
                            <a href="#"><i class="fab fa-vk"></i></a>
                            <a href="#"><i class="fab fa-telegram"></i></a>
                            <a href="#"><i class="fab fa-instagram"></i></a>
                            <a href="#"><i class="fab fa-youtube"></i></a>
                        </div>
                    </div>
                </div>
                
                <div class="footer-bottom">
                    <p>&copy; 2024 AutoElite. Все права защищены.</p>
                    <p><a href="admin.php" style="color: #800020; text-decoration: none;">🔐 Администратору</a></p>
                </div>
            </div>
        </footer>
    </main>

    <div class="modal-overlay hidden" id="modalOverlay">
        <div class="modal" id="modal">
            <button class="modal-close" id="modalClose">
                <i class="fas fa-times"></i>
            </button>
            <h2 class="modal-title">Связь с нами</h2>
        </div>
    </div>

    <script>
        // Основной скрипт для слайдера и AJAX отправки формы анкеты
        document.addEventListener('DOMContentLoaded', function() {
            // Слайдер
            const slider = document.querySelector('.slider');
            const slides = document.querySelectorAll('.slide');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const indicators = document.querySelectorAll('.indicator');
            let currentSlide = 0;
            const totalSlides = slides.length;
            
            function updateSlider() {
                if (slider) slider.style.transform = `translateX(-${currentSlide * 100}%)`;
                indicators.forEach((indicator, index) => {
                    if (index === currentSlide) indicator.classList.add('active');
                    else indicator.classList.remove('active');
                });
            }
            
            if (nextBtn) {
                nextBtn.addEventListener('click', function() {
                    currentSlide = (currentSlide + 1) % totalSlides;
                    updateSlider();
                });
            }
            
            if (prevBtn) {
                prevBtn.addEventListener('click', function() {
                    currentSlide = (currentSlide - 1 + totalSlides) % totalSlides;
                    updateSlider();
                });
            }
            
            indicators.forEach(indicator => {
                indicator.addEventListener('click', function() {
                    currentSlide = parseInt(this.getAttribute('data-slide'));
                    updateSlider();
                });
            });
            
            let slideInterval = setInterval(() => {
                currentSlide = (currentSlide + 1) % totalSlides;
                updateSlider();
            }, 5000);
            
            if (slider) {
                slider.addEventListener('mouseenter', () => clearInterval(slideInterval));
                slider.addEventListener('mouseleave', () => {
                    slideInterval = setInterval(() => {
                        currentSlide = (currentSlide + 1) % totalSlides;
                        updateSlider();
                    }, 5000);
                });
            }
            
            // Плавная прокрутка
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    const href = this.getAttribute('href');
                    if (href === '#') return;
                    e.preventDefault();
                    const targetElement = document.querySelector(href);
                    if (targetElement) {
                        const headerHeight = document.querySelector('.navbar')?.offsetHeight || 80;
                        const targetPosition = targetElement.offsetTop - headerHeight - 20;
                        window.scrollTo({ top: targetPosition, behavior: 'smooth' });
                    }
                    const mobileMenu = document.getElementById('mobileMenu');
                    if (mobileMenu && mobileMenu.classList.contains('active')) {
                        mobileMenu.classList.remove('active');
                    }
                });
            });
            
            // Мобильное меню
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const mobileMenu = document.getElementById('mobileMenu');
            const closeMenuBtn = document.getElementById('closeMenuBtn');
            
            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', function() {
                    mobileMenu.classList.add('active');
                    document.body.style.overflow = 'hidden';
                });
            }
            
            if (closeMenuBtn) {
                closeMenuBtn.addEventListener('click', function() {
                    mobileMenu.classList.remove('active');
                    document.body.style.overflow = '';
                });
            }
            
            // Изменение фона навигации
            window.addEventListener('scroll', function() {
                const navbar = document.querySelector('.navbar');
                if (navbar) {
                    if (window.scrollY > 100) {
                        navbar.style.backgroundColor = 'rgba(13, 27, 42, 0.95)';
                        navbar.style.boxShadow = '0 5px 20px rgba(0, 0, 0, 0.1)';
                    } else {
                        navbar.style.backgroundColor = 'rgba(13, 27, 42, 0.9)';
                        navbar.style.boxShadow = 'none';
                    }
                }
            });
            
            updateSlider();
            
            // -------------------- AJAX ОТПРАВКА ФОРМЫ АНКЕТЫ --------------------
            const developerForm = document.getElementById('developerForm');
            const formSubmitBtn = document.getElementById('formSubmitBtn');
            const ajaxMessages = document.getElementById('ajaxMessages');
            
            function clearErrors() {
                document.querySelectorAll('.error-message-dev').forEach(el => el.innerHTML = '');
                document.querySelectorAll('.form-group-dev input, .form-group-dev select, .form-group-dev textarea').forEach(el => {
                    el.classList.remove('form-error-dev');
                });
            }
            
            function showErrors(errors) {
                for (const [field, message] of Object.entries(errors)) {
                    const errorEl = document.getElementById(`${field}_error`);
                    if (errorEl) {
                        errorEl.innerHTML = message;
                    }
                    const inputEl = document.getElementById(field);
                    if (inputEl) {
                        inputEl.classList.add('form-error-dev');
                    }
                    if (field === 'languages') {
                        const selectEl = document.getElementById('languages');
                        if (selectEl) selectEl.classList.add('form-error-dev');
                    }
                    if (field === 'contract') {
                        const contractEl = document.getElementById('contract');
                        if (contractEl) contractEl.classList.add('form-error-dev');
                    }
                }
            }
            
            function showMessage(message, isSuccess = true) {
                if (ajaxMessages) {
                    const msgDiv = document.createElement('div');
                    msgDiv.className = isSuccess ? 'success-message-dev' : 'success-message-dev';
                    msgDiv.style.cssText = isSuccess ? '' : 'background:#f8d7da; color:#721c24;';
                    msgDiv.innerHTML = message;
                    ajaxMessages.appendChild(msgDiv);
                    setTimeout(() => msgDiv.remove(), 8000);
                }
            }
            
            if (developerForm) {
                developerForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    clearErrors();
                    if (ajaxMessages) ajaxMessages.innerHTML = '';
                    
                    const formData = new FormData(developerForm);
                    const data = {};
                    for (let [key, value] of formData.entries()) {
                        if (key === 'languages[]') {
                            if (!data['languages']) data['languages'] = [];
                            data['languages'].push(value);
                        } else if (key === 'contract') {
                            data['contract'] = true;
                        } else {
                            data[key] = value;
                        }
                    }
                    if (!data['languages']) data['languages'] = [];
                    if (!data['contract']) data['contract'] = false;
                    
                    if (formSubmitBtn) {
                        formSubmitBtn.disabled = true;
                        formSubmitBtn.textContent = 'Отправка...';
                    }
                    
                    try {
                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify(data)
                        });
                        
                        const result = await response.json();
                        
                        if (result.success) {
                            showMessage(result.messages.join('<br>'), true);
                            if (result.credentials) {
                                showMessage(`✅ Ваши данные для входа:<br><br>Логин: <b>${result.credentials.login}</b><br>Пароль: <b>${result.credentials.password}</b><br><br>⚠️ Сохраните их! Теперь вы можете авторизоваться и редактировать свои данные.`, true);
                            }
                            if (result.updated) {
                                setTimeout(() => window.location.reload(), 2000);
                            } else {
                                setTimeout(() => window.location.reload(), 3000);
                            }
                        } else if (result.errors) {
                            showErrors(result.errors);
                            showMessage('Пожалуйста, исправьте ошибки в форме.', false);
                        }
                    } catch (error) {
                        console.error('Ошибка:', error);
                        showMessage('Ошибка при отправке данных. Попробуйте еще раз.', false);
                    } finally {
                        if (formSubmitBtn) {
                            formSubmitBtn.disabled = false;
                            formSubmitBtn.textContent = '<?= isset($_SESSION['user_id']) ? '✏️ Обновить анкету' : '✉️ Отправить анкету' ?>';
                        }
                    }
                });
            }
            
            // Контактная форма
            const contactForm = document.getElementById('contactForm');
            if (contactForm) {
                contactForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const submitBtn = document.getElementById('contactSubmitBtn');
                    const spinner = document.getElementById('contactSpinner');
                    const formMessage = document.getElementById('contactFormMessage');
                    const submitText = submitBtn.querySelector('span');
                    const originalText = submitText.textContent;
                    
                    submitBtn.disabled = true;
                    spinner.classList.remove('hidden');
                    submitText.textContent = 'Отправка...';
                    formMessage.classList.add('hidden');
                    
                    const formData = new FormData(contactForm);
                    const data = Object.fromEntries(formData.entries());
                    const formcarryData = {
                        ...data,
                        _subject: 'Новая заявка с сайта AutoElite',
                        _replyto: data.email || '',
                        _gotcha: '',
                        source: 'main_form',
                        timestamp: new Date().toLocaleString('ru-RU')
                    };
                    
                    try {
                        const response = await fetch('https://formcarry.com/s/6hnv04gn1c2', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify(formcarryData)
                        });
                        const result = await response.json();
                        if (response.ok && result.code === 200) {
                            formMessage.textContent = '✅ Спасибо за заявку! Мы свяжемся с вами в течение 15 минут.';
                            formMessage.className = 'form-message success';
                            formMessage.classList.remove('hidden');
                            contactForm.reset();
                        } else {
                            throw new Error(result.message || 'Ошибка отправки');
                        }
                    } catch (error) {
                        formMessage.textContent = `❌ Ошибка: ${error.message}. Пожалуйста, попробуйте еще раз.`;
                        formMessage.className = 'form-message error';
                        formMessage.classList.remove('hidden');
                    } finally {
                        submitBtn.disabled = false;
                        spinner.classList.add('hidden');
                        submitText.textContent = originalText;
                        setTimeout(() => formMessage.classList.add('hidden'), 5000);
                    }
                });
            }
        });
    </script>
    <script src="script.js"></script>
</body>
</html>
