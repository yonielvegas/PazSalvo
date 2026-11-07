<?php
require __DIR__.'/../../vendor/autoload.php';
// Schema was migrated normally in the isolated test DB; never run migrate:fresh.
Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated=true;
