<?php

// Constants Mautic defines when it boots; PHPStan does not boot it.
if (!defined('MAUTIC_TABLE_PREFIX')) {
    define('MAUTIC_TABLE_PREFIX', '');
}
