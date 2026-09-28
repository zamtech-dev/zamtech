<?php
require_once __DIR__ . '/_auth.php';

$_SESSION = [];
session_destroy();

header('Location: /blog/admin/login.php');
exit;
