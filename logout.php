<?php
session_start();
session_destroy();

$ref = $_SERVER['HTTP_REFERER'] ?? '';
$redirect = str_contains($ref, 'partner/') ? 'partner/home/index.php' : 'login.php';
header('Location: ' . $redirect);
exit;
