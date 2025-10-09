<?php
if (!function_exists('inv_cfg')) {
    function inv_cfg(string $key, $default=null){ return config('inventory.'.$key, $default); }
}
