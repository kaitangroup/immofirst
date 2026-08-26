<?php

$settings['rebuild_access'] = TRUE;
$settings['skip_permissions_hardening'] = TRUE;

$config['system.logging']['error_level'] = 'verbose';

// Disable caches for development.
$settings['cache']['bins']['render'] = 'cache.backend.null';
$settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';
$settings['cache']['bins']['page'] = 'cache.backend.null';

$settings['container_yamls'][] = DRUPAL_ROOT . '/sites/default/services.local.yml';

$databases['default']['default'] = [
    'database' => 'immofirst',
    'username' => 'root',
    'password' => '',
    'host' => '127.0.0.1',
    'port' => '3306',
    'driver' => 'mysql',
    'prefix' => '',
  ];

  $settings['config_sync_directory'] = 'sites/default/files/config_YqC7n15PoL-9FYichsrbLBKj7gZA-t0rDiJdiTdv4EeytdKGNE1uiy9krrZzywqybGXrWlv3lw/sync';
