<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/repository.php';
require __DIR__ . '/src/app.php';

agenthub_app()->run();
