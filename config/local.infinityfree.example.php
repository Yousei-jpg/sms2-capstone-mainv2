<?php

/**
 * Example InfinityFree deployment configuration.
 *
 * Copy this file to config/local.infinityfree.php and replace every placeholder
 * with values from the hosting control panel. The real configuration file is
 * excluded from Git.
 */

define('SMS2_DEPLOY_TOKEN', 'replace-with-a-long-random-token');
define('BASE_URL', 'https://your-site.example.com');

define('DB_HOST', 'sqlXXX.infinityfree.com');
define('DB_PORT', 3306);
define('DB_NAME', 'if0_XXXXXXXX_sms2');
define('DB_USER', 'if0_XXXXXXXX');
define('DB_PASS', 'replace-with-your-database-password');
define('DB_CHARSET', 'utf8mb4');

// Keep these names aligned when all modules share one database.
define('CRAD_DB_NAME', 'if0_XXXXXXXX_sms2');
define('STUDENT_PORTAL_DB_NAME', 'if0_XXXXXXXX_sms2');
define('REPORTS_DB_NAME', 'if0_XXXXXXXX_sms2');
define('USERMGMT_DB_NAME', 'if0_XXXXXXXX_sms2');

