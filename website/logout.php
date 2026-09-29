<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$_SESSION = [];
session_destroy();

redirect_to('index.php');
