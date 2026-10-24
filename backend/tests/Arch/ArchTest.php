<?php

declare(strict_types=1);

arch('strict types are declared in all app, module, and shared classes')
    ->expect(['App', 'Modules', 'Shared'])
    ->toUseStrictTypes();

arch('controllers do not use the DB facade directly')
    ->expect('Illuminate\Support\Facades\DB')
    ->not->toBeUsedIn([
        'App\Http\Controllers',
        'Modules\*\Http\Controllers',
    ]);

$modulesDir = dirname(__DIR__, 2).'/Modules';
$modules = array_values(array_filter(
    array_map('basename', glob($modulesDir.'/*') ?: []),
    fn (string $name): bool => is_dir($modulesDir.'/'.$name)
));

foreach ($modules as $module) {
    arch("models in {$module} are only used within that module")
        ->expect("Modules\\{$module}\\Models")
        ->toOnlyBeUsedIn([
            "Modules\\{$module}",
            'Tests',
            'Database',
        ]);
}

arch('money classes do not use float')
    ->expect('Shared\Money')
    ->not->toUse(['float', 'double']);
