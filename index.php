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
// СОЗДАНИЕ ТАБЛИЦ (автоматически)
// --------------------

try {
    // Отключаем проверку внешних ключей для безопасного удаления
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Удаляем старые связующие таблицы если есть
    $pdo->exec("DROP TABLE IF EXISTS application_cars");
    $pdo->exec("DROP TABLE IF EXISTS applications");
    $pdo->exec("DROP TABLE IF EXISTS cars");
    
    // Включаем проверку обратно
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Создаем таблицу автомобилей
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cars (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) UNIQUE NOT NULL,
            brand VARCHAR(50),
            price_min INT,
            image_url VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Создаем таблицу заявок
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Создаем связующую таблицу
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS application_cars (
            application_id INT NOT NULL,
            car_id INT NOT NULL,
            PRIMARY KEY (application_id, car_id),
            FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
            FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Добавляем автомобили (только если таблица пустая)
    $checkCars = $pdo->query("SELECT COUNT(*) as cnt FROM cars")->fetch();
    if ($checkCars['cnt'] == 0) {
        $cars = [
            ['Porsche Panamera', 'Porsche', 9500000, 'https://i.pinimg.com/1200x/b9/ea/01/b9ea017e55f040aea05079c907258b35.jpg'],
            ['Mercedes-Benz S-Class', 'Mercedes-Benz', 12000000, 'https://i.pinimg.com/1200x/5d/74/97/5d749788759bc112b30f99158c4a2b87.jpg'],
            ['BMW 7 Series', 'BMW', 8900000, 'https://i.pinimg.com/1200x/20/cc/3b/20cc3b1b0ec4220d4d4e35e73de480c9.jpg'],
            ['Audi A8', 'Audi', 8500000, null],
            ['Lexus LS', 'Lexus', 9200000, null],
            ['Range Rover', 'Land Rover', 11000000, null],
            ['Bentley Continental', 'Bentley', 18000000, null],
            ['Ferrari Roma', 'Ferrari', 22000000, null]
        ];
        
        $stmt = $pdo->prepare("INSERT INTO cars (name, brand, price_min, image_url) VALUES (?, ?, ?, ?)");
        foreach ($cars as $car) {
            $stmt->execute($car);
        }
    }
    
} catch(PDOException $e) {
    // Если ошибка, просто логируем и продолжаем (таблицы уже существуют)
    error_log('Table creation error: ' . $e->getMessage());
}

// --------------------
// ПОЛУЧАЕМ СПИСОК АВТОМОБИЛЕЙ
// --------------------

$carsList = $pdo->query("SELECT id, name, brand, price_min, image_url FROM cars ORDER BY name")->fetchAll();

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
// ОБРАБОТКА ФОРМЫ
// --------------------

$messages = [];
$loginError = '';
$justSaved = false;
$errorMessages = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['login_submit'])) {
    $errors = false;

    // Валидация
    if (empty($_POST['full_name']) || !preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u', $_POST['full_name'])) {
        $errorMessages['full_name'] = 'ФИО обязательно и может содержать только буквы, пробелы и дефисы.';
        $errors = true;
    }

    if (empty($_POST['phone']) || !preg_match('/^(\+7|8)?[\s\-]?\(?[0-9]{3}\)?[\s\-]?[0-9]{3}[\s\-]?[0-9]{2}[\s\-]?[0-9]{2}$/', $_POST['phone'])) {
        $errorMessages['phone'] = 'Введите корректный номер телефона.';
        $errors = true;
    }

    if (empty($_POST['email']) || !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errorMessages['email'] = 'Введите корректный e-mail.';
        $errors = true;
    }

    if (empty($_POST['birth_date'])) {
        $errorMessages['birth_date'] = 'Выберите дату рождения.';
        $errors = true;
    }

    if (empty($_POST['gender']) || !in_array($_POST['gender'], ['male', 'female', 'other'])) {
        $errorMessages['gender'] = 'Выберите пол.';
        $errors = true;
    }

    $selectedCars = $_POST['cars'] ?? [];
    if (empty($selectedCars)) {
        $errorMessages['cars'] = 'Выберите хотя бы один автомобиль.';
        $errors = true;
    }

    if (!isset($_POST['contract'])) {
        $errorMessages['contract'] = 'Необходимо принять условия.';
        $errors = true;
    }

    $formValues = [
        'full_name' => $_POST['full_name'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'email' => $_POST['email'] ?? '',
        'birth_date' => $_POST['birth_date'] ?? '',
        'gender' => $_POST['gender'] ?? '',
        'cars' => $selectedCars,
        'bio' => $_POST['bio'] ?? '',
        'contract' => isset($_POST['contract'])
    ];

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if (isset($_SESSION['user_id'])) {
                // UPDATE
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
                    $_SESSION['user_id']
                ]);
                
                // Обновляем выбранные автомобили
                $pdo->prepare("DELETE FROM application_cars WHERE application_id=?")->execute([$_SESSION['user_id']]);
                $stmtCar = $pdo->prepare("INSERT INTO application_cars (application_id, car_id) VALUES (?, ?)");
                foreach ($selectedCars as $carId) {
                    $stmtCar->execute([$_SESSION['user_id'], $carId]);
                }
                
                $messages[] = '✅ Данные успешно обновлены!';
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
                
                // Сохраняем выбранные автомобили
                $stmtCar = $pdo->prepare("INSERT INTO application_cars (application_id, car_id) VALUES (?, ?)");
                foreach ($selectedCars as $carId) {
                    $stmtCar->execute([$appId, $carId]);
                }
                
                $_SESSION['generated_login'] = $login;
                $_SESSION['generated_password'] = $plainPassword;
                $justSaved = true;
                
                $messages[] = '✅ Данные успешно сохранены!';
            }

            $pdo->commit();
            
        } catch(PDOException $e) {
            $pdo->rollBack();
            $errorMessages['db_error'] = 'Ошибка БД: ' . $e->getMessage();
        }
    }
}

// Авторизация
if (isset($_POST['login_submit'])) {
    $login = trim($_POST['login'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($login) || empty($password)) {
        $loginError = 'Введите логин и пароль';
    } else {
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

// Выход
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// Загрузка данных для формы
if (isset($formValues)) {
    $values = $formValues;
    $errors = $errorMessages ?? [];
} elseif (isset($_SESSION['user_id'])) {
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

        $stmt = $pdo->prepare("SELECT car_id FROM application_cars WHERE application_id=?");
        $stmt->execute([$_SESSION['user_id']]);
        $values['cars'] = array_column($stmt->fetchAll(), 'car_id');
    }
} else {
    $values = [
        'full_name' => '',
        'phone' => '',
        'email' => '',
        'birth_date' => '',
        'gender' => '',
        'cars' => [],
        'bio' => '',
        'contract' => false
    ];
    $errors = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoElite - Заявка на автомобиль</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .form-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 800px;
            margin: 0 auto;
        }
        .form-header {
            background: #0d1b2a;
            color: white;
            padding: 30px;
            text-align: center;
        }
        .form-header h2 {
            font-family: 'Roboto', sans-serif;
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .form-body {
            padding: 40px;
        }
        .auth-card {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
        }
        .btn-admin {
            background: #800020;
            color: white;
            padding: 12px 25px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-admin:hover {
            background: #a00028;
            transform: translateY(-2px);
        }
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .login-credentials {
            background: #e8f4f8;
            border-left: 4px solid #17a2b8;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .error-message {
            color: #dc3545;
            font-size: 0.85em;
            margin-top: 5px;
        }
        .form-error {
            border-color: #dc3545 !important;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1em;
        }
        select[multiple] {
            height: 150px;
        }
        .radio-group {
            display: flex;
            gap: 20px;
            padding: 10px 0;
        }
        .form-checkbox {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        .form-checkbox input {
            margin-right: 10px;
        }
        .btn-submit {
            background: #9E9E9E;
            color: #800020;
            border: none;
            padding: 14px 30px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            width: 100%;
        }
        .btn-submit:hover {
            background: #757575;
        }
        .slider-container {
            position: relative;
            max-width: 900px;
            margin: 0 auto;
            overflow: hidden;
        }
        .slider {
            display: flex;
            transition: transform 0.5s ease;
        }
        .slide {
            min-width: 100%;
            padding: 40px;
            text-align: center;
        }
        .slider-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0,0,0,0.5);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
        }
        .prev-btn { left: 10px; }
        .next-btn { right: 10px; }
        .slider-indicators {
            text-align: center;
            margin-top: 10px;
        }
        .indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #ccc;
            margin: 0 5px;
            cursor: pointer;
        }
        .indicator.active {
            background: #800020;
        }
        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .service-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .footer {
            background: #0d1b2a;
            color: white;
            padding: 40px 0;
        }
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 30px;
        }
        .social-icons a {
            color: white;
            margin-right: 15px;
            font-size: 1.2rem;
        }
        @media (max-width: 768px) {
            .form-body { padding: 20px; }
            .services-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="video-background">
            <video autoplay muted loop playsinline>
                <source src="assets/video/large-vecteezy_selective-focus-on-a-car-male-customer-talking-to-auto_33116350_x-large.mp4" type="video/mp4">
            </video>
            <div class="video-overlay"></div>
        </div>
        
        <nav class="navbar">
            <div class="container nav-container">
                <div class="logo">
                    <h1>AutoElite</h1>
                    <p>Премиальный автосалон с 2010 года</p>
                </div>
                
                <ul class="nav-menu">
                    <li><a href="#home">Главная</a></li>
                    <li><a href="#catalog">Каталог</a></li>
                    <li><a href="#services">Услуги</a></li>
                    <li><a href="#form">Анкета</a></li>
                    <li><a href="admin.php" class="btn-admin"><i class="fas fa-shield-alt"></i> Администратору</a></li>
                </ul>
                
                <div class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </div>
            </div>
        </nav>
        
        <div class="mobile-menu" id="mobileMenu">
            <div class="mobile-menu-header">
                <h2>AutoElite</h2>
                <button class="close-menu" id="closeMenuBtn"><i class="fas fa-times"></i></button>
            </div>
            <ul class="mobile-nav">
                <li><a href="#home">Главная</a></li>
                <li><a href="#catalog">Каталог</a></li>
                <li><a href="#services">Услуги</a></li>
                <li><a href="#form">Анкета</a></li>
                <li><a href="admin.php"><i class="fas fa-shield-alt"></i> Админ-панель</a></li>
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
                        <?php foreach ($carsList as $index => $car): ?>
                        <div class="slide <?= $index === 0 ? 'active' : '' ?>">
                            <h3><?= htmlspecialchars($car['name']) ?></h3>
                            <p><?= htmlspecialchars($car['brand']) ?></p>
                            <p class="slide-price">от <?= number_format($car['price_min'], 0, '', ' ') ?> ₽</p>
                            <a href="#form" class="btn-order">Оставить заявку</a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="slider-btn prev-btn" id="prevBtn"><i class="fas fa-chevron-left"></i></button>
                    <button class="slider-btn next-btn" id="nextBtn"><i class="fas fa-chevron-right"></i></button>
                    <div class="slider-indicators">
                        <?php foreach ($carsList as $index => $car): ?>
                        <span class="indicator <?= $index === 0 ? 'active' : '' ?>" data-slide="<?= $index ?>"></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="services" id="services">
            <div class="container">
                <h2 class="section-title">Наши услуги</h2>
                <p class="section-subtitle">Полный комплекс услуг для вашего комфорта</p>
                <div class="services-grid">
                    <div class="service-card"><i class="fas fa-car service-icon"></i><h3>Продажа новых авто</h3><p>Широкий выбор новых автомобилей премиум-класса</p></div>
                    <div class="service-card"><i class="fas fa-credit-card service-icon"></i><h3>Кредитование</h3><p>Выгодные программы кредитования и лизинга</p></div>
                    <div class="service-card"><i class="fas fa-exchange-alt service-icon"></i><h3>Трейд-ин</h3><p>Выгодный обмен вашего автомобиля на новую модель</p></div>
                    <div class="service-card"><i class="fas fa-search service-icon"></i><h3>Поиск авто</h3><p>Поиск и доставка автомобилей по индивидуальным требованиям</p></div>
                    <div class="service-card"><i class="fas fa-tools service-icon"></i><h3>Сервисное обслуживание</h3><p>Полное ТО и ремонт в собственном сервисном центре</p></div>
                    <div class="service-card"><i class="fas fa-spray-can service-icon"></i><h3>Детейлинг</h3><p>Премиум-уход за автомобилем</p></div>
                </div>
            </div>
        </section>

        <section class="contact-form-section" id="form">
            <div class="container">
                <div class="form-container">
                    <div class="form-header">
                        <h2>📝 Анкета клиента</h2>
                        <p>Заполните форму, чтобы получить персональное предложение</p>
                    </div>
                    <div class="form-body">
                        <?php if (!empty($_SESSION['generated_login']) && $justSaved): ?>
                            <div class="login-credentials">
                                <strong>✅ Ваши данные для входа:</strong><br>
                                Логин: <b><?= htmlspecialchars($_SESSION['generated_login']) ?></b><br>
                                Пароль: <b><?= htmlspecialchars($_SESSION['generated_password']) ?></b><br>
                                <small>⚠️ Сохраните их для редактирования анкеты!</small>
                            </div>
                            <?php unset($_SESSION['generated_login'], $_SESSION['generated_password']); ?>
                        <?php endif; ?>

                        <?php foreach($messages as $m): ?>
                            <div class="success-message"><?= $m ?></div>
                        <?php endforeach; ?>

                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <div class="auth-card">
                                <h3>🔐 Авторизация для редактирования</h3>
                                <?php if ($loginError): ?>
                                    <div class="error-message"><?= $loginError ?></div>
                                <?php endif; ?>
                                <form method="POST">
                                    <div class="form-group">
                                        <input type="text" name="login" placeholder="Логин">
                                    </div>
                                    <div class="form-group">
                                        <input type="password" name="password" placeholder="Пароль">
                                    </div>
                                    <button type="submit" name="login_submit" class="btn-submit">Войти</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="success-message">
                                ✅ Вы авторизованы как <strong><?= htmlspecialchars($values['full_name']) ?></strong>
                                <a href="?logout=1" style="float:right; color:#800020;">Выйти</a>
                            </div>
                        <?php endif; ?>

                        <form method="POST" id="applicationForm">
                            <div class="form-group">
                                <label>ФИО *</label>
                                <input type="text" name="full_name" value="<?= htmlspecialchars($values['full_name'] ?? '') ?>">
                                <div class="error-message"><?= $errors['full_name'] ?? '' ?></div>
                            </div>

                            <div class="form-group">
                                <label>Телефон *</label>
                                <input type="tel" name="phone" value="<?= htmlspecialchars($values['phone'] ?? '') ?>">
                                <div class="error-message"><?= $errors['phone'] ?? '' ?></div>
                            </div>

                            <div class="form-group">
                                <label>E-mail *</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($values['email'] ?? '') ?>">
                                <div class="error-message"><?= $errors['email'] ?? '' ?></div>
                            </div>

                            <div class="form-group">
                                <label>Дата рождения *</label>
                                <input type="date" name="birth_date" value="<?= htmlspecialchars($values['birth_date'] ?? '') ?>">
                                <div class="error-message"><?= $errors['birth_date'] ?? '' ?></div>
                            </div>

                            <div class="form-group">
                                <label>Пол *</label>
                                <div class="radio-group">
                                    <label><input type="radio" name="gender" value="male" <?= (($values['gender'] ?? '') == 'male') ? 'checked' : '' ?>> Мужской</label>
                                    <label><input type="radio" name="gender" value="female" <?= (($values['gender'] ?? '') == 'female') ? 'checked' : '' ?>> Женский</label>
                                    <label><input type="radio" name="gender" value="other" <?= (($values['gender'] ?? '') == 'other') ? 'checked' : '' ?>> Другой</label>
                                </div>
                                <div class="error-message"><?= $errors['gender'] ?? '' ?></div>
                            </div>

                            <div class="form-group">
                                <label>Интересующие автомобили *</label>
                                <select name="cars[]" multiple>
                                    <?php foreach ($carsList as $car): ?>
                                        <option value="<?= $car['id'] ?>" <?= in_array($car['id'], $values['cars'] ?? []) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($car['brand'] . ' ' . $car['name']) ?> - от <?= number_format($car['price_min'], 0, '', ' ') ?> ₽
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small>Удерживайте Ctrl (Cmd) для выбора нескольких</small>
                                <div class="error-message"><?= $errors['cars'] ?? '' ?></div>
                            </div>

                            <div class="form-group">
                                <label>Пожелания к заказу</label>
                                <textarea name="bio" rows="4" placeholder="Опишите ваши пожелания: комплектация, цвет, дополнительные опции..."><?= htmlspecialchars($values['bio'] ?? '') ?></textarea>
                            </div>

                            <div class="form-checkbox">
                                <input type="checkbox" name="contract" value="1" <?= !empty($values['contract']) ? 'checked' : '' ?>>
                                <label>Я согласен с условиями обработки персональных данных *</label>
                                <div class="error-message"><?= $errors['contract'] ?? '' ?></div>
                            </div>

                            <button type="submit" class="btn-submit">
                                <?= isset($_SESSION['user_id']) ? '✏️ Обновить анкету' : '✉️ Отправить заявку' ?>
                            </button>
                        </form>
                    </div>
                </div>
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
                </div>
            </div>
        </footer>
    </main>

    <script src="script.js"></script>
    <script>
        // Слайдер
        const slider = document.querySelector('.slider');
        const slides = document.querySelectorAll('.slide');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const indicators = document.querySelectorAll('.indicator');
        let currentSlide = 0;
        
        function updateSlider() {
            if (slider) slider.style.transform = `translateX(-${currentSlide * 100}%)`;
            indicators.forEach((ind, i) => {
                if (i === currentSlide) ind.classList.add('active');
                else ind.classList.remove('active');
            });
        }
        
        if (prevBtn && nextBtn) {
            nextBtn.addEventListener('click', () => {
                currentSlide = (currentSlide + 1) % slides.length;
                updateSlider();
            });
            prevBtn.addEventListener('click', () => {
                currentSlide = (currentSlide - 1 + slides.length) % slides.length;
                updateSlider();
            });
        }
        
        indicators.forEach((ind, i) => {
            ind.addEventListener('click', () => {
                currentSlide = i;
                updateSlider();
            });
        });
        
        // Мобильное меню
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileMenu = document.getElementById('mobileMenu');
        const closeMenuBtn = document.getElementById('closeMenuBtn');
        
        if (mobileMenuBtn && mobileMenu) {
            mobileMenuBtn.addEventListener('click', () => {
                mobileMenu.classList.add('active');
                document.body.style.overflow = 'hidden';
            });
            if (closeMenuBtn) {
                closeMenuBtn.addEventListener('click', () => {
                    mobileMenu.classList.remove('active');
                    document.body.style.overflow = '';
                });
            }
        }
    </script>
</body>
</html>
