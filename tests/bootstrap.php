<?php

use Tests\TestDatabaseGuard;

require dirname(__DIR__).'/vendor/autoload.php';

TestDatabaseGuard::ensureProcessEnvironment();
TestDatabaseGuard::synchronizeLaravelEnvironment();
