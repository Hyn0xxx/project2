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
// СОЗДАНИЕ ТАБЛИЦ (если не существуют)
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

// Добавляем автомобили вместо языков программирования
$cars = ['Porsche Panamera', 'Mercedes-Benz S-Class', 'BMW 7 Series', 'Audi A8', 'Lexus LS', 'Range Rover', 'Bentley Continental', 'Ferrari Roma'];
$stmt = $pdo->prepare("INSERT IGNORE INTO programming_languages (name) VALUES (?)");
foreach ($cars as $car) {
    $stmt->execute([$car]);
}

// Список автомобилей
$carsList = $pdo->query("SELECT id, name FROM programming_languages ORDER BY name")->fetchAll();
$allowedCarIds = array_column($carsList, 'id');

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
// ОБРАБОТКА (FALLBACK для отключенного JS)
// --------------------

$messages = [];
$loginError = '';
$showLoginForm = !isset($_SESSION['user_id']);
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

    $selectedCars = $_POST['languages'] ?? [];
    if (empty($selectedCars)) {
        $errorMessages['languages'] = 'Выберите хотя бы один автомобиль.';
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
        'bio' => $_POST['bio'] ?? '',
        'contract' => isset($_POST['contract']),
        'languages' => $selectedCars
    ];

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if (isset($_SESSION['user_id'])) {
                // UPDATE
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
                
                $_SESSION['generated_login'] = $login;
                $_SESSION['generated_password'] = $plainPassword;
                $justSaved = true;
                
                $messages[] = '✅ Данные успешно сохранены!';
            }

            $stmtLang = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($selectedCars as $carId) {
                $stmtLang->execute([$appId, $carId]);
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

        $stmt = $pdo->prepare("SELECT language_id FROM application_languages WHERE application_id=?");
        $stmt->execute([$_SESSION['user_id']]);
        $values['languages'] = array_column($stmt->fetchAll(), 'language_id');
    }
} else {
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
        /* Дополнительные стили для формы */
        .form-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 800px;
            margin: 0 auto;
        }
        .form-header {
            background: var(--primary-color);
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
        .login-credentials p {
            margin: 5px 0;
        }
        .error-message {
            color: #dc3545;
            font-size: 0.85em;
            margin-top: 5px;
        }
        .form-error {
            border-color: #dc3545 !important;
        }
    </style>
</head>
<body>
    <!-- Шапка с видео -->
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
                    <li><a href="#about">О нас</a></li>
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
                <li><a href="#about">О нас</a></li>
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
        <!-- Популярные модели (слайдер) -->
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
                                <a href="#form" class="btn-order">Оставить заявку</a>
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
                                <a href="#form" class="btn-order">Оставить заявку</a>
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
                                <a href="#form" class="btn-order">Оставить заявку</a>
                            </div>
                        </div>
                    </div>
                    <button class="slider-btn prev-btn" id="prevBtn"><i class="fas fa-chevron-left"></i></button>
                    <button class="slider-btn next-btn" id="nextBtn"><i class="fas fa-chevron-right"></i></button>
                    <div class="slider-indicators">
                        <span class="indicator active" data-slide="0"></span>
                        <span class="indicator" data-slide="1"></span>
                        <span class="indicator" data-slide="2"></span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Услуги -->
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

        <!-- ФОРМА АНКЕТЫ -->
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
                                <strong>✅ Ваши данные для входа для редактирования анкеты:</strong><br>
                                Логин: <b><?= htmlspecialchars($_SESSION['generated_login']) ?></b><br>
                                Пароль: <b><?= htmlspecialchars($_SESSION['generated_password']) ?></b><br>
                                <small>⚠️ Сохраните их! Теперь вы можете авторизоваться и редактировать свои данные.</small>
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
                                    <div class="error-message" style="margin-bottom:10px;"><?= $loginError ?></div>
                                <?php endif; ?>
                                <form method="POST" id="loginForm">
                                    <div class="form-group">
                                        <input type="text" name="login" placeholder="Логин" style="width:100%; padding:12px; border-radius:8px; border:1px solid #ddd;">
                                    </div>
                                    <div class="form-group">
                                        <input type="password" name="password" placeholder="Пароль" style="width:100%; padding:12px; border-radius:8px; border:1px solid #ddd;">
                                    </div>
                                    <button type="submit" name="login_submit" class="btn-submit" style="background:#6c757d;">Войти</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="success-message">
                                ✅ Вы авторизованы как <strong><?= htmlspecialchars($values['full_name']) ?></strong>
                                <a href="?logout=1" style="float:right; color:#800020;">Выйти</a>
                            </div>
                        <?php endif; ?>

                        <!-- Форма анкеты -->
                        <form method="POST" id="applicationForm" data-ajax="true">
                            <div class="form-group">
                                <label>ФИО *</label>
                                <input type="text" name="full_name" id="full_name" value="<?= htmlspecialchars($values['full_name'] ?? '') ?>" class="<?= isset($errors['full_name']) ? 'form-error' : '' ?>">
                                <div class="error-message" id="error_full_name"><?= $errors['full_name'] ?? '' ?></div>
                            </div>

                            <div class="form-group">
                                <label>Телефон *</label>
                                <input type="tel" name="phone" id="phone" value="<?= htmlspecialchars($values['phone'] ?? '') ?>" class="<?= isset($errors['phone']) ? 'form-error' : '' ?>">
                                <div class="error-message" id="error_phone"><?= $errors['phone'] ?? '' ?></div>
                            </div>

                            <div class="form-group">
                                <label>E-mail *</label>
                                <input type="email" name="email" id="email" value="<?= htmlspecialchars($values['email'] ?? '') ?>" class="<?= isset($errors['email']) ? 'form-error' : '' ?>">
                                <div class="error-message" id="error_email"><?= $errors['email'] ?? '' ?></div>
                            </div>

                            <div class="form-group">
                                <label>Дата рождения *</label>
                                <input type="date" name="birth_date" id="birth_date" value="<?= htmlspecialchars($values['birth_date'] ?? '') ?>" class="<?= isset($errors['birth_date']) ? 'form-error' : '' ?>">
                                <div class="error-message" id="error_birth_date"><?= $errors['birth_date'] ?? '' ?></div>
                            </div>

                            <div class="form-group">
                                <label>Пол *</label>
                                <div class="radio-group">
                                    <label><input type="radio" name="gender" value="male" <?= (($values['gender'] ?? '') == 'male') ? 'checked' : '' ?>> Мужской</label>
                                    <label><input type="radio" name="gender" value="female" <?= (($values['gender'] ?? '') == 'female') ? 'checked' : '' ?>> Женский</label>
                                    <label><input type="radio" name="gender" value="other" <?= (($values['gender'] ?? '') == 'other') ? 'checked' : '' ?>> Другой</label>
                                </div>
                                <div class="error-message" id="error_gender"><?= $errors['gender'] ?? '' ?></div>
                            </div>

                            <div class="form-group">
                                <label>Интересующие автомобили *</label>
                                <select name="languages[]" id="languages" multiple class="<?= isset($errors['languages']) ? 'form-error' : '' ?>">
                                    <?php foreach ($carsList as $car): ?>
                                        <option value="<?= $car['id'] ?>" <?= in_array($car['id'], $values['languages'] ?? []) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($car['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="color:#666;">Удерживайте Ctrl (Cmd) для выбора нескольких</small>
                                <div class="error-message" id="error_languages"><?= $errors['languages'] ?? '' ?></div>
                            </div>

                            <div class="form-group">
                                <label>Пожелания к заказу</label>
                                <textarea name="bio" id="bio" rows="4" placeholder="Опишите ваши пожелания: комплектация, цвет, дополнительные опции..."><?= htmlspecialchars($values['bio'] ?? '') ?></textarea>
                            </div>

                            <div class="form-checkbox">
                                <input type="checkbox" name="contract" id="contract" value="1" <?= !empty($values['contract']) ? 'checked' : '' ?>>
                                <label for="contract">Я согласен с условиями обработки персональных данных *</label>
                                <div class="error-message" id="error_contract"><?= $errors['contract'] ?? '' ?></div>
                            </div>

                            <button type="submit" class="btn-submit" id="submitBtn">
                                <span><?= isset($_SESSION['user_id']) ? '✏️ Обновить анкету' : '✉️ Отправить заявку' ?></span>
                                <div class="spinner hidden" id="spinner"></div>
                            </button>
                        </form>

                        <div id="ajaxMessage" class="form-message hidden" style="margin-top:20px;"></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Футер -->
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
        // AJAX отправка формы
        (function() {
            const form = document.getElementById('applicationForm');
            if (!form) return;
            
            const submitBtn = document.getElementById('submitBtn');
            const spinner = document.getElementById('spinner');
            const ajaxMessage = document.getElementById('ajaxMessage');
            
            function showMessage(text, type) {
                ajaxMessage.textContent = text;
                ajaxMessage.className = `form-message ${type}`;
                ajaxMessage.classList.remove('hidden');
                setTimeout(() => ajaxMessage.classList.add('hidden'), 5000);
            }
            
            function clearErrors() {
                document.querySelectorAll('.error-message').forEach(el => el.textContent = '');
                document.querySelectorAll('.form-error').forEach(el => el.classList.remove('form-error'));
            }
            
            function displayErrors(errors) {
                for (const [field, message] of Object.entries(errors)) {
                    const errorEl = document.getElementById(`error_${field}`);
                    if (errorEl) {
                        errorEl.textContent = message;
                    }
                    const inputEl = document.querySelector(`[name="${field}"]`);
                    if (inputEl) inputEl.classList.add('form-error');
                    if (field === 'languages') {
                        document.getElementById('languages')?.classList.add('form-error');
                    }
                    if (field === 'contract') {
                        document.getElementById('contract')?.closest('.form-checkbox')?.classList.add('form-error');
                    }
                }
            }
            
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                clearErrors();
                submitBtn.disabled = true;
                spinner.classList.remove('hidden');
                submitBtn.querySelector('span').textContent = 'Отправка...';
                
                const formData = new FormData(form);
                const data = {};
                for (let [key, value] of formData.entries()) {
                    if (key === 'languages[]') {
                        if (!data.languages) data.languages = [];
                        data.languages.push(value);
                    } else {
                        data[key] = value;
                    }
                }
                
                try {
                    const isAuth = <?= isset($_SESSION['user_id']) ? 'true' : 'false' ?>;
                    const url = isAuth ? 'api.php?action=update' : 'api.php?action=create';
                    
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(data)
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        if (result.login && result.password) {
                            showMessage(`✅ Заявка отправлена! Ваш логин: ${result.login}, пароль: ${result.password}. Сохраните их для редактирования.`, 'success');
                            form.reset();
                        } else if (result.message) {
                            showMessage(result.message, 'success');
                        }
                        // Перезагружаем страницу чтобы обновить состояние авторизации
                        setTimeout(() => window.location.reload(), 2000);
                    } else {
                        if (result.errors) {
                            displayErrors(result.errors);
                        }
                        showMessage(result.message || 'Ошибка при отправке', 'error');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showMessage('Ошибка соединения. Попробуйте позже.', 'error');
                } finally {
                    submitBtn.disabled = false;
                    spinner.classList.add('hidden');
                    submitBtn.querySelector('span').textContent = <?= isset($_SESSION['user_id']) ? "'✏️ Обновить анкету'" : "'✉️ Отправить заявку'" ?>;
                }
            });
        })();
    </script>
</body>
</html>
