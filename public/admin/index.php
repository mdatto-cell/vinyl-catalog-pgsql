<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/layout.php';
require_login();
header('Location: ' . app_url('/'));
exit;
