<?php

$projectRoot = dirname(__DIR__, 4);
require $projectRoot . '/config/bootstrap_test.php';

putenv('INTEGRATION_TESTS_ENABLED=1');

if (getenv('ORO_DB_URL') === false && isset($_ENV['ORO_DB_URL'])) {
    putenv('ORO_DB_URL=' . $_ENV['ORO_DB_URL']);
}
