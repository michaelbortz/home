<?php
// Cron: */15 * * * * /usr/bin/php /usr/www/users/aijnuy/ntfy/bin/poll.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../src/poller.php';

echo date('c'), ' ', poll(), "\n";
