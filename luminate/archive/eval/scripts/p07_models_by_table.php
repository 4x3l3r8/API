<?php
// Load every model class shipped in vendor/escolalms + app, group by the table it queries.
$files = array_merge(
    glob(base_path('vendor/escolalms/*/src/Models/*.php')) ?: [],
    glob(base_path('vendor/escolalms/*/src/Models/*/*.php')) ?: [],
    glob(base_path('vendor/escolalms/*/src/Models/*/*/*.php')) ?: [],
    glob(base_path('app/Models/*.php')) ?: []
);
$byTable = [];
$total = 0;
foreach ($files as $f) {
    $src = file_get_contents($f);
    if (!preg_match('/^namespace\s+([^;]+);/m', $src, $ns) || !preg_match('/^(?:abstract\s+)?class\s+(\w+)/m', $src, $cl)) {
        continue;
    }
    $class = $ns[1] . '\\' . $cl[1];
    try {
        if (!class_exists($class)) continue;
        $r = new ReflectionClass($class);
        if ($r->isAbstract() || !$r->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) continue;
        $t = (new $class())->getTable();
    } catch (\Throwable $e) {
        continue;
    }
    $byTable[$t][] = $class;
    $total++;
}
ksort($byTable);
echo "concrete Eloquent model classes: $total over " . count($byTable) . " tables\n";
$multi = array_filter($byTable, fn ($c) => count($c) > 1);
echo "tables queried by more than one model class: " . count($multi) . "\n";
uasort($multi, fn ($a, $b) => count($b) <=> count($a));
foreach (array_slice($multi, 0, 12, true) as $t => $cs) {
    echo sprintf("  %-26s %2d classes: %s\n", $t, count($cs), implode(', ', array_map(fn ($c) => preg_replace('/^EscolaLms\\\\/', '', $c), array_slice($cs, 0, 4))) . (count($cs) > 4 ? ', ...' : ''));
}
file_put_contents('/tmp/p07.json', json_encode($byTable, JSON_PRETTY_PRINT));
