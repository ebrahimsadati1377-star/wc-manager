<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
requirePostAndCsrfOrFail();
Auth::logout();
redirect('login.php');
