<?php

// Explicit opt-in/user and the XML password default prevent using application .env credentials.
if (getenv('PAK_DISPOSABLE_DB') !== 'pak_02a1_test' || getenv('DB_USERNAME') === false) {
    throw new RuntimeException('Set PAK_DISPOSABLE_DB=pak_02a1_test and explicit disposable DB_USERNAME (DB_PASSWORD if needed).');
}

require dirname(__DIR__, 3).'/vendor/autoload.php';
