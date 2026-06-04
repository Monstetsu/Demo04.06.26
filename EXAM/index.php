<?php
session_start();
error_reporting(0);

$db = new SQLite3('database.sqlite');

$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    full_name TEXT NOT NULL,
    phone TEXT NOT NULL,
    email TEXT NOT NULL,
    role TEXT DEFAULT 'user'
)");

$db->exec("CREATE TABLE IF NOT EXISTS courses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL
)");

$db->exec("CREATE TABLE IF NOT EXISTS requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    course_id INTEGER NOT NULL,
    start_date TEXT NOT NULL,
    payment_method TEXT NOT NULL,
    status TEXT DEFAULT 'Новая',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id),
    FOREIGN KEY(course_id) REFERENCES courses(id)
)");

$db->exec("CREATE TABLE IF NOT EXISTS reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    request_id INTEGER NOT NULL,
    review_text TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id),
    FOREIGN KEY(request_id) REFERENCES requests(id)
)");

$courseCheck = $db->querySingle("SELECT COUNT(*) FROM courses");
if ($courseCheck == 0) {
    $courses = ['Курсы повышения квалификации', 'Курсы переподготовки', 'Охрана труда для руководителей и специалистов', 'Электробезопасность'];
    foreach ($courses as $course) {
        $stmt = $db->prepare("INSERT INTO courses (name) VALUES (:name)");
        $stmt->bindValue(':name', $course, SQLITE3_TEXT);
        $stmt->execute();
    }
}

$adminCheck = $db->querySingle("SELECT COUNT(*) FROM users WHERE username = 'Admin26'");
if ($adminCheck == 0) {
    $hashed = password_hash('Demo20', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password, full_name, phone, email, role) VALUES ('Admin26', :pass, 'Администратор', '+7 (495) 123-45-67', 'admin@uchus.ru', 'admin')");
    $stmt->bindValue(':pass', $hashed, SQLITE3_TEXT);
    $stmt->execute();
}

$action = $_GET['action'] ?? 'home';
$error_msg = $_SESSION['flash_error'] ?? null;
$success_msg = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

if ($action == 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Регистрация
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $errors = [];

    if (!preg_match('/^[a-zA-Z0-9]{6,}$/', $username)) $errors[] = "Логин: мин 6 символов (латиница+цифры)";
    if (strlen($password) < 8) $errors[] = "Пароль не менее 8 символов";
    if (empty($full_name)) $errors[] = "Введите ФИО";
    if (empty($phone)) $errors[] = "Введите телефон";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Некорректный email";

    $check = $db->prepare("SELECT id FROM users WHERE username = :username");
    $check->bindValue(':username', $username, SQLITE3_TEXT);
    if ($check->execute()->fetchArray()) $errors[] = "Логин занят";

    if (!$errors) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, full_name, phone, email) VALUES (:username, :pass, :full, :phone, :email)");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':pass', $hashed, SQLITE3_TEXT);
        $stmt->bindValue(':full', $full_name, SQLITE3_TEXT);
        $stmt->bindValue(':phone', $phone, SQLITE3_TEXT);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->execute();
        $_SESSION['user_id'] = $db->lastInsertRowID();
        $_SESSION['username'] = $username;
        $_SESSION['role'] = 'user';
        $_SESSION['flash_success'] = "Регистрация успешна!";
        header('Location: index.php?action=dashboard');
        exit;
    } else {
        $_SESSION['flash_error'] = implode('<br>', $errors);
        header('Location: index.php?action=register');
        exit;
    }
}

// Логин
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['flash_success'] = "Добро пожаловать!";
        header('Location: index.php?action=dashboard');
        exit;
    } else {
        $_SESSION['flash_error'] = "Неверный логин или пароль";
        header('Location: index.php?action=login');
        exit;
    }
}

// Создание заявки
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_request']) && isset($_SESSION['user_id'])) {
    $course_id = intval($_POST['course_id']);
    $start_date = $_POST['start_date'];
    $payment_method = $_POST['payment_method'];

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
        $parts = explode('-', $start_date);
        $start_date_f = $parts[2] . '.' . $parts[1] . '.' . $parts[0];
        $stmt = $db->prepare("INSERT INTO requests (user_id, course_id, start_date, payment_method) VALUES (:uid, :cid, :sdate, :pm)");
        $stmt->bindValue(':uid', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':cid', $course_id, SQLITE3_INTEGER);
        $stmt->bindValue(':sdate', $start_date_f, SQLITE3_TEXT);
        $stmt->bindValue(':pm', $payment_method, SQLITE3_TEXT);
        $stmt->execute();
        $_SESSION['flash_success'] = "Заявка успешно создана!";
    } else {
        $_SESSION['flash_error'] = "Неверный формат даты";
    }
    header('Location: index.php?action=new_request');
    exit;
}

// Отзыв
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_review']) && isset($_SESSION['user_id'])) {
    $request_id = intval($_POST['request_id']);
    $text = trim($_POST['review_text']);
    if (!$text) {
        $_SESSION['flash_error'] = "Введите текст отзыва";
        header('Location: index.php?action=dashboard');
        exit;
    }
    $check = $db->prepare("SELECT id FROM requests WHERE id = :rid AND user_id = :uid AND status = 'Обучение завершено'");
    $check->bindValue(':rid', $request_id, SQLITE3_INTEGER);
    $check->bindValue(':uid', $_SESSION['user_id'], SQLITE3_INTEGER);
    if ($check->execute()->fetchArray()) {
        $stmt = $db->prepare("INSERT INTO reviews (user_id, request_id, review_text) VALUES (:uid, :rid, :txt)");
        $stmt->bindValue(':uid', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':rid', $request_id, SQLITE3_INTEGER);
        $stmt->bindValue(':txt', $text, SQLITE3_TEXT);
        $stmt->execute();
        $_SESSION['flash_success'] = "Отзыв добавлен!";
    } else {
        $_SESSION['flash_error'] = "Отзыв можно оставить только для завершённых заявок";
    }
    header('Location: index.php?action=dashboard');
    exit;
}

// Смена статуса (админ)
if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin' && isset($_GET['change_status'])) {
    $req_id = intval($_GET['req_id']);
    $new_status = $_GET['new_status'];
    $allowed = ['Новая', 'Идет обучение', 'Обучение завершено'];
    if (in_array($new_status, $allowed)) {
        $stmt = $db->prepare("UPDATE requests SET status = :status WHERE id = :id");
        $stmt->bindValue(':status', $new_status, SQLITE3_TEXT);
        $stmt->bindValue(':id', $req_id, SQLITE3_INTEGER);
        $stmt->execute();
        $_SESSION['flash_success'] = "Статус изменён";
    }
    $filter = $_GET['status_filter'] ?? '';
    $sort = $_GET['sort'] ?? 'created_desc';
    $page = $_GET['page'] ?? 1;
    header("Location: index.php?action=admin&status=$filter&sort=$sort&page=$page");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Учусь.РФ - Образовательный портал</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Inter',sans-serif; }
        body { background:#f4f7fc; }
        .container { max-width:1200px; margin:0 auto; padding:0 20px; }
        header { background:#fff; box-shadow:0 2px 10px rgba(0,0,0,0.05); position:sticky; top:0; z-index:100; }
        .header-inner { display:flex; justify-content:space-between; align-items:center; padding:15px 0; flex-wrap:wrap; gap:15px; }
        .logo-area { display:flex; align-items:center; gap:12px; }
        .logo-img { width:45px; height:45px; background:#007bff; border-radius:12px; display:flex; align-items:center; justify-content:center; color:white; font-weight:bold; font-size:20px; }
        .logo-text { font-size:1.6rem; font-weight:700; color:#0d47a1; }
        .logo-text span { color:#007bff; }
        nav { display:flex; gap:20px; align-items:center; flex-wrap:wrap; }
        nav a { text-decoration:none; color:#2c3e50; font-weight:500; transition:0.2s; }
        nav a:hover { color:#007bff; }
        .btn { display:inline-block; background:#007bff; color:white; padding:8px 18px; border-radius:40px; text-decoration:none; border:none; cursor:pointer; transition:0.2s; }
        .btn-outline { background:transparent; border:1px solid #007bff; color:#007bff; }
        .btn-outline:hover { background:#007bff; color:white; }
        .card { background:white; border-radius:24px; padding:28px; box-shadow:0 8px 20px rgba(0,0,0,0.05); margin-bottom:30px; }
        h1, h2, h3 { font-weight:600; margin-bottom:20px; color:#0d47a1; }
        .error { background:#f8d7da; color:#721c24; padding:12px; border-radius:16px; margin-bottom:20px; }
        .success { background:#d4edda; color:#155724; padding:12px; border-radius:16px; margin-bottom:20px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px; text-align:left; border-bottom:1px solid #dee2e6; }
        th { background:#f8f9fa; }
        .status-badge { padding:4px 12px; border-radius:50px; font-size:0.8rem; display:inline-block; }
        .status-new { background:#ffc107; color:#856404; }
        .status-progress { background:#17a2b8; color:white; }
        .status-done { background:#28a745; color:white; }
        .image-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:20px; margin:30px 0; }
        .grid-item { background:#fff; border-radius:20px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.1); text-align:center; padding:15px; }
        .grid-item img { width:100%; height:180px; object-fit:cover; border-radius:12px; background:#f0f0f0; }
        .slider-container { position:relative; margin:20px 0; border-radius:24px; overflow:hidden; }
        .slider { display:flex; transition:transform 0.5s ease; }
        .slide { min-width:100%; }
        .slide img { width:100%; height:300px; object-fit:cover; border-radius:20px; background:#f0f0f0; }
        .slider-btn { position:absolute; top:50%; transform:translateY(-50%); background:rgba(0,0,0,0.5); color:white; border:none; padding:10px 15px; font-size:24px; border-radius:50%; cursor:pointer; }
        .prev { left:10px; }
        .next { right:10px; }
        .filter-bar { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
        footer { background:#e9ecef; margin-top:40px; padding:30px; text-align:center; }
        @media (max-width:768px) { .slide img { height:200px; } .card { padding:18px; } }
    </style>
</head>
<body>
<header>
    <div class="container header-inner">
        <div class="logo-area">
            <img src="/images/logo.png">
            <div class="logo-text">Учусь.<span>РФ</span></div>
        </div>
        <nav>
            <a href="index.php?action=home">Главная</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="index.php?action=dashboard">Личный кабинет</a>
                <a href="index.php?action=new_request">Новая заявка</a>
                <?php if (($_SESSION['role'] ?? '') == 'admin'): ?>
                    <a href="index.php?action=admin" class="btn-outline btn">Админ-панель</a>
                <?php endif; ?>
                <a href="index.php?action=logout">Выйти (<?= htmlspecialchars($_SESSION['username']) ?>)</a>
            <?php else: ?>
                <a href="index.php?action=login">Вход</a>
                <a href="index.php?action=register">Регистрация</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<div class="container">
    <?php if ($error_msg): ?><div class="error"><?= $error_msg ?></div><?php endif; ?>
    <?php if ($success_msg): ?><div class="success"><?= $success_msg ?></div><?php endif; ?>

<?php
// ========== ГЛАВНАЯ ==========
if ($action == 'home'): ?>
    <div class="card" style="text-align:center;">
        <h1>Повышение квалификации и переподготовка</h1>
        <p>Дистанционное обучение – ваш выбор. Удостоверения, сертификаты, свидетельства.</p>
        <p style="margin:20px 0;"><img src="images/hero.png" alt="Удостоверение" style="max-width:100%; border-radius:20px;" onerror="this.style.display='none'"></p>
        <a href="index.php?action=register" class="btn">Начать обучение</a>
    </div>

    <div class="image-grid">
        <div class="grid-item"><img src="images/1675870847_grizly-club-p-klipart-ku.jpg" alt="Курсы"><h3>Курсы повышения квалификации</h3></div>
        <div class="grid-item"><img src="images/2.png" alt="Охрана труда"><h3>Охрана труда</h3><p>Для руководителей и специалистов</p></div>
        <div class="grid-item"><img src="images/2fa84186f893bd1de6c63573037de9.jpg" alt="Педагогам"><h3>Курсы для педагогов</h3></div>
        <div class="grid-item"><img src="images/i.webp" alt="Рабочие профессии"><h3>Рабочие профессии</h3><p>Подготовка / Переподготовка</p></div>
        <div class="grid-item"><img src="images/i (3).webp" alt="Электробезопасность"><h3>Электробезопасность</h3></div>
        <div class="grid-item"><img src="images/slide18-l-1.jpg" alt="Сертификация"><h3>Переподготовка</h3><p>Сертификация</p></div>
        <div class="grid-item"><img src="images/quality.webp" alt="Качество"><h3>100% контроль</h3><p>Гарантия качества</p></div>
        <div class="grid-item"><img src="images/XXL_height.webp" alt="Преимущества"><h3>Сочетание цены и качества</h3></div>
    </div>
    
    <div class="card">
        <h2>Наши преимущества</h2>
        <div style="display:flex; flex-wrap:wrap; gap:20px; align-items:center;">
            <div><img src="images/XXL_height.webp" width="60" alt="Цена и качество"><p>Цена и качество</p></div>
            <div><img src="images/i (1).webp" width="60" alt="Дистанционно"><p>Дистанционное обучение</p></div>
            <div><img src="images/Приглашение_на_курсы.jpg" width="60" alt="Приглашаем"><p>Приглашаем на курсы</p></div>
        </div>
    </div>

<?php elseif ($action == 'register'): ?>
    <div class="card"><h2>Регистрация</h2>
        <form method="POST">
            <input type="text" name="username" placeholder="Логин (латиница, 6+ символов)" required>
            <input type="password" name="password" placeholder="Пароль (8+ символов)" required>
            <input type="text" name="full_name" placeholder="ФИО" required>
            <input type="text" name="phone" placeholder="Телефон" required>
            <input type="email" name="email" placeholder="Email" required>
            <button type="submit" name="register" class="btn">Зарегистрироваться</button>
        </form>
    </div>

<?php elseif ($action == 'login'): ?>
    <div class="card"><h2>Вход</h2>
        <form method="POST">
            <input type="text" name="username" placeholder="Логин" required>
            <input type="password" name="password" placeholder="Пароль" required>
            <button type="submit" name="login" class="btn">Войти</button>
        </form>
        <p style="margin-top:15px;">Нет аккаунта? <a href="index.php?action=register">Регистрация</a></p>
    </div>

<?php elseif ($action == 'dashboard' && isset($_SESSION['user_id'])):
    $uid = $_SESSION['user_id'];
    $reqs = $db->prepare("SELECT r.*, c.name as course_name FROM requests r JOIN courses c ON r.course_id = c.id WHERE r.user_id = :uid ORDER BY r.created_at DESC");
    $reqs->bindValue(':uid', $uid, SQLITE3_INTEGER);
    $reqs = $reqs->execute();
    $reviews = $db->prepare("SELECT rev.*, c.name as course_name FROM reviews rev JOIN requests r ON rev.request_id = r.id JOIN courses c ON r.course_id = c.id WHERE rev.user_id = :uid");
    $reviews->bindValue(':uid', $uid, SQLITE3_INTEGER);
    $reviews = $reviews->execute();
?>
    <div class="card">
        <h2>Личный кабинет</h2>
        <div class="slider-container">
            <div class="slider" id="mainSlider">
                <div class="slide"><img src="images/1675870847_grizly-club-p-klipart-ku.jpg" alt="Курсы"></div>
                <div class="slide"><img src="images/2.png" alt="Охрана труда"></div>
                <div class="slide"><img src="images/slide18-l-1.jpg" alt="Переподготовка"></div>
                <div class="slide"><img src="images/hero.png" alt="Удостоверение"></div>
            </div>
            <button class="slider-btn prev" onclick="changeSlide(-1)">❮</button>
            <button class="slider-btn next" onclick="changeSlide(1)">❯</button>
        </div>

        <h3>Мои заявки</h3>
        <tr><thead> hilab<th>Курс</th><th>Дата</th><th>Оплата</th><th>Статус</th><th>Отзыв</th></tr></thead>
        <tbody><?php while($row = $reqs->fetchArray(SQLITE3_ASSOC)): ?>
        <tr><td><?= htmlspecialchars($row['course_name']) ?></td>
            <td><?= $row['start_date'] ?></td>
            <td><?= $row['payment_method'] ?></td>
            <td><span class="status-badge status-<?= $row['status']=='Новая'?'new':($row['status']=='Идет обучение'?'progress':'done') ?>"><?= $row['status'] ?></span></td>
            <td><?php if($row['status']=='Обучение завершено'): ?>
                <form method="POST" style="display:inline-flex; gap:5px;"><input type="hidden" name="request_id" value="<?= $row['id'] ?>"><input type="text" name="review_text" placeholder="Отзыв" style="width:120px;"><button type="submit" name="add_review" class="btn" style="padding:4px 12px;">Ок</button></form>
                <?php else: ?>—<?php endif; ?></td>
        </tr><?php endwhile; ?></tbody>
        </table>
        <h3>Мои отзывы</h3><ul><?php while($rev = $reviews->fetchArray(SQLITE3_ASSOC)): ?><li><strong><?= htmlspecialchars($rev['course_name']) ?>:</strong> <?= htmlspecialchars($rev['review_text']) ?></li><?php endwhile; ?></ul>
    </div>

<?php elseif ($action == 'new_request' && isset($_SESSION['user_id'])):
    $coursesList = $db->query("SELECT id, name FROM courses"); ?>
    <div class="card"><h2>Новая заявка</h2>
        <form method="POST">
            <select name="course_id" required><?php while($c = $coursesList->fetchArray(SQLITE3_ASSOC)): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endwhile; ?></select>
            <input type="date" name="start_date" required>
            <select name="payment_method" required><option>предоплата по QR-коду</option><option>оплата картой МИР</option><option>постоплата в офисе</option></select>
            <button type="submit" name="create_request" class="btn">Отправить заявку</button>
        </form>
    </div>

<?php elseif ($action == 'admin' && ($_SESSION['role'] ?? '') == 'admin'):
    $status_filter = $_GET['status'] ?? '';
    $sort = $_GET['sort'] ?? 'created_desc';
    $page = max(1, intval($_GET['page'] ?? 1));
    $perpage = 5;
    $offset = ($page-1)*$perpage;
    $sql = "SELECT r.*, u.username, u.full_name, c.name as course_name FROM requests r JOIN users u ON r.user_id = u.id JOIN courses c ON r.course_id = c.id";
    if ($status_filter && in_array($status_filter, ['Новая','Идет обучение','Обучение завершено'])) $sql .= " WHERE r.status = :status";
    $order = ($sort == 'date_asc') ? " ORDER BY r.created_at ASC" : (($sort == 'status_asc') ? " ORDER BY r.status ASC" : " ORDER BY r.created_at DESC");
    $sql .= $order . " LIMIT $perpage OFFSET $offset";
    $stmt = $db->prepare($sql);
    if ($status_filter) $stmt->bindValue(':status', $status_filter, SQLITE3_TEXT);
    $adminReqs = $stmt->execute();
    $total = $db->querySingle("SELECT COUNT(*) FROM requests".($status_filter ? " WHERE status='$status_filter'" : ""));
    $totalPages = ceil($total/$perpage);
?>
    <div class="card"><h2>Панель администратора</h2>
        <div class="filter-bar"><form method="GET" style="display:flex; gap:10px;"><input type="hidden" name="action" value="admin"><select name="status"><option value="">Все</option><option value="Новая" <?= $status_filter=='Новая'?'selected':'' ?>>Новая</option><option value="Идет обучение" <?= $status_filter=='Идет обучение'?'selected':'' ?>>Идет обучение</option><option value="Обучение завершено" <?= $status_filter=='Обучение завершено'?'selected':'' ?>>Завершено</option></select><select name="sort"><option value="created_desc" <?= $sort=='created_desc'?'selected':'' ?>>Новые</option><option value="date_asc" <?= $sort=='date_asc'?'selected':'' ?>>Старые</option><option value="status_asc" <?= $sort=='status_asc'?'selected':'' ?>>По статусу</option></select><button type="submit" class="btn">Фильтр</button></form></div>
        <table><thead><tr><th>ID</th><th>Пользователь</th><th>Курс</th><th>Дата</th><th>Оплата</th><th>Статус</th><th>Действие</th></tr></thead>
        <tbody><?php while($row = $adminReqs->fetchArray(SQLITE3_ASSOC)): ?>
        <tr><td><?= $row['id'] ?></td><td><?= htmlspecialchars($row['full_name']) ?></td><td><?= $row['course_name'] ?></td><td><?= $row['start_date'] ?></td><td><?= $row['payment_method'] ?></td>
            <td><span class="status-badge status-<?= $row['status']=='Новая'?'new':($row['status']=='Идет обучение'?'progress':'done') ?>"><?= $row['status'] ?></span></td>
            <td><a href="?action=admin&change_status=1&req_id=<?= $row['id'] ?>&new_status=Новая&status_filter=<?= urlencode($status_filter) ?>&sort=<?= urlencode($sort) ?>&page=<?= $page ?>" class="btn-outline btn" style="font-size:12px;">Новая</a>
                <a href="?action=admin&change_status=1&req_id=<?= $row['id'] ?>&new_status=Идет обучение&status_filter=<?= urlencode($status_filter) ?>&sort=<?= urlencode($sort) ?>&page=<?= $page ?>" class="btn-outline btn" style="font-size:12px;">Обучение</a>
                <a href="?action=admin&change_status=1&req_id=<?= $row['id'] ?>&new_status=Обучение завершено&status_filter=<?= urlencode($status_filter) ?>&sort=<?= urlencode($sort) ?>&page=<?= $page ?>" class="btn-outline btn" style="font-size:12px;">Завершено</a></td>
        </tr><?php endwhile; ?></tbody>
        </table>
        <div class="pagination"><?php for($i=1;$i<=$totalPages;$i++): ?><a href="?action=admin&status=<?= urlencode($status_filter) ?>&sort=<?= urlencode($sort) ?>&page=<?= $i ?>" class="btn-outline btn" style="margin:2px;"><?= $i ?></a><?php endfor; ?></div>
    </div>
<?php elseif ($action == 'dashboard' && !isset($_SESSION['user_id'])):
    header('Location: index.php?action=login');
    exit;
endif; ?>
</div>
<footer>
    <p>© 2026 Учусь.РФ — Москва, ул. Большая Ордынка, д. 15 | Тел: +7 (495) 123-45-67</p>
</footer>
<script>
    let slideIndex = 0;
    const slides = document.querySelectorAll('#mainSlider .slide');
    if(slides.length) {
        function showSlide(n) {
            if (n >= slides.length) slideIndex = 0;
            if (n < 0) slideIndex = slides.length - 1;
            document.getElementById('mainSlider').style.transform = `translateX(${-slideIndex * 100}%)`;
        }
        window.changeSlide = function(direction) { slideIndex += direction; showSlide(slideIndex); };
        setInterval(() => changeSlide(1), 3000);
        showSlide(0);
    }
</script>
</body>
</html>