<?php

// Set error logging to log every error
error_reporting(E_ALL);
ini_set('display_errors', TRUE);
ini_set('display_startup_errors', TRUE);
$config['system.logging']['error_level'] = 'verbose';

// Turns off preprocessing of css and js files, leaving them to be loaded
// without minification
$config['system.performance']['css']['preprocess'] = FALSE;
$config['system.performance']['js']['preprocess'] = FALSE;

// Loads misc drupal services - See services.yml file
// Uncomment if needed
// $settings['container_yamls'][] = $app_root . '/sites/default/local.development.services.yml';

// Disable the render cache and disable dynamic page cache
// This causes the site to be slower locally, but helps out
// in development
// Uncomment if needed
// $settings['cache']['bins']['render'] = 'cache.backend.null';
// $settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';
// $settings['cache']['bins']['page'] = 'cache.backend.null';

// Drupal >= 8.8
// Changes the default location of the config_sync_directory. Default
// location is sites/default/files/config, but by having the config outside
// of web root, we can more easilly have the config stored in git, without
// comprimising the config data being exposed.
$settings['config_sync_directory'] = $app_root . '/../config/sync';

// These module configs should never be exported, since they are development modules
$settings['config_exclude_modules'] = ['devel', 'stage_file_proxy'];

// See: https://www.drupal.org/docs/installing-drupal/trusted-host-settings
$settings['trusted_host_patterns'] = [
'^.+\.ddev\.site$'
];

// Environment indicator
// name local dev test stage live
// fg_color #ffffff #ffffff #4d7750 #642828 #acf9ff
// bg_color #000000 #5a5a5a #1dea59 #f3f200 #ff0000
$config['environment_indicator.indicator']['name'] = 'local';
$config['environment_indicator.indicator']['fg_color'] = '#ffffff';
$config['environment_indicator.indicator']['bg_color'] = '#000000';
