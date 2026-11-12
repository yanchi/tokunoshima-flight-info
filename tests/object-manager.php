<?php

// Entity manager for phpstan-doctrine (phpstan.dist.neon). Boots the test
// kernel; no database connection is opened.

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__).'/.env', 'test');

$kernel = new Kernel('test', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
