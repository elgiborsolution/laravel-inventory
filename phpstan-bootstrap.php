<?php

use Composer\InstalledVersions;

if (! defined('LARAVEL_VERSION')) {
    define('LARAVEL_VERSION', InstalledVersions::getPrettyVersion('illuminate/support') ?? '9.0.0');
}
