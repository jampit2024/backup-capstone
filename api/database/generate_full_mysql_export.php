<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\Schema;

$sqlitePath = __DIR__.'/database.sqlite';
if (! file_exists($sqlitePath)) {
    echo "Missing database.sqlite\n";
    exit(1);
}

$pdo = new PDO('sqlite:'.$sqlitePath);
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

echo "Found ".count($tables)." tables in SQLite:\n";
foreach ($tables as $t) {
    echo " - $t\n";
}
