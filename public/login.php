<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

$error = '';
$returnTo = safe_return_url($_POST['return'] ?? $_GET['return'] ?? '/');
if (is_logged_in()) {
    header('Location: ' . $returnTo);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (attempt_login($username, $password)) {
        header('Location: ' . $returnTo);
        exit;
    }

    $error = 'Неверный логин или пароль.';
}
page_head('Вход в каталог');
?>
<main class="container login-container" id="content">
    <h1>Вход</h1>
    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($returnTo) ?>">
        <div style="margin-bottom:16px;">
            <label>Логин</label>
            <input type="text" name="username" required>
        </div>
        <div style="margin-bottom:16px;">
            <label>Пароль</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit">Войти</button>
    </form>
</main>
<?php page_foot(); ?>
