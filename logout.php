<?php
require_once __DIR__ . '/lib/bootstrap.php';
sqlbak_start_session();
$_SESSION = [];
session_destroy();
header('Location: login.php');
