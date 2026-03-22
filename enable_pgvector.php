<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    echo "pgvector enabled!\n";
} catch (Throwable $e) {
    echo 'Error: '.$e->getMessage()."\n";
}
