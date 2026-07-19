<?php

$settings['rebuild_access'] = TRUE;
$settings['skip_permissions_hardening'] = TRUE;

$config['system.logging']['error_level'] = 'verbose';

// Disable caches for development.
$settings['cache']['bins']['render'] = 'cache.backend.null';
$settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';
$settings['cache']['bins']['page'] = 'cache.backend.null';

$settings['container_yamls'][] = DRUPAL_ROOT . '/sites/default/services.local.yml';