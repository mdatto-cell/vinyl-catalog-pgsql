<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function status_label(string $status): string
{
    return match ($status) {
        'in_stock' => 'В наличии',
        'expected' => 'Ожидаю',
        'lent_out' => 'Дал послушать',
        default => $status,
    };
}

function normalize_decimal(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    return str_replace(',', '.', $value);
}

function ensure_upload_dir(int $recordId): string
{
    $dir = UPLOAD_DIR . '/' . $recordId;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        exit('Сессия формы устарела. Обнови страницу и повтори отправку.');
    }
}
