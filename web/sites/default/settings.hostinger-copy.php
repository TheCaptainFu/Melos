<?php

// phpcs:ignoreFile

// Production settings for Hostinger.
// Copy this file to settings.hostinger.php on the server and fill in the values.
// DO NOT commit settings.hostinger.php to git.

$databases['default']['default'] = [
  'database' => 'YOUR_DB_NAME',
  'username' => 'YOUR_DB_USER',
  'password' => 'YOUR_DB_PASSWORD',
  'host' => 'localhost',
  'port' => '3306',
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];

$settings['hash_salt'] = 'PASTE_A_LONG_RANDOM_STRING_HERE_MIN_55_CHARS';

$settings['trusted_host_patterns'] = [
  '^yourdomain\.com$',
  '^www\.yourdomain\.com$',
];

// Disable verbose error output on production.
$config['system.logging']['error_level'] = 'hide';

// Enable CSS/JS aggregation on production.
$config['system.performance']['css']['preprocess'] = TRUE;
$config['system.performance']['js']['preprocess'] = TRUE;
