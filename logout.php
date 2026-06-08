<?php
// logout.php — end session and return to login
require_once __DIR__ . '/config/db.php';
logout();
header('Location: ' . BASE_URL . '/login.php');
exit;
