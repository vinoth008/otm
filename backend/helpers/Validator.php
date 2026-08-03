<?php
declare(strict_types=1);

function clean_string(?string $value): string
{
    return trim((string)$value);
}

function valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function valid_mobile(string $mobile): bool
{
    return (bool)preg_match('/^[0-9]{10,15}$/', $mobile);
}

function valid_password(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

function valid_username(string $username): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_.]{4,30}$/', $username);
}