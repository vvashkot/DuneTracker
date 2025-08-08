<?php
/**
 * Configuration loader
 * Loads local config if it exists, otherwise loads production config
 */

$config_file = dirname(__DIR__) . '/config.local.php';
if (!file_exists($config_file)) {
    $config_file = dirname(__DIR__) . '/config.php';
}

require_once $config_file;