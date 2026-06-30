<?php
$templates = App\Domain\REM\Models\RemTemplate::all(['id','year','rem_type','version','is_active']);
print_r($templates->toArray());

$t = App\Domain\REM\Models\RemTemplate::where('is_active', true)->first();
if ($t) {
    echo "\n\nTemplate: " . $t->rem_type . ' ' . $t->year . PHP_EOL;
    echo json_encode($t->config, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo 'NO HAY TEMPLATES ACTIVOS' . PHP_EOL;
}
