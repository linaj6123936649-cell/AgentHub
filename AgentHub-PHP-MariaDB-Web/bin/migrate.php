#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/repository.php';
$repo = new AgentHubRepository(agenthub_pdo(), agenthub_config());
$repo->migrate();
echo "AgentHub schema ready\n";
