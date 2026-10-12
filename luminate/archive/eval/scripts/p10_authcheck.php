<?php
\Pooled\Spike\TenantContext::$forced = 'pooled-d.localhost';
echo "forced tenant D:" . PHP_EOL;
echo "  App\Models\User::find(2) => " . (\App\Models\User::find(2) ? 'FOUND (carol visible to D)' : 'null (hidden from D)') . PHP_EOL;
echo "  sql: " . (new \App\Models\User)->newQuery()->where('id', 2)->toSql() . PHP_EOL;
$p = app('auth')->createUserProvider('users');
echo "  provider class: " . get_class($p) . PHP_EOL;
echo "  provider->retrieveById(2) => " . ($p->retrieveById(2) ? 'FOUND' : 'null') . PHP_EOL;
$g = config('auth.guards.api');
echo "  api guard: " . json_encode($g) . PHP_EOL;
echo "  passport userModel config: " . json_encode(config('auth.providers')) . PHP_EOL;
\Pooled\Spike\TenantContext::$forced = null;
