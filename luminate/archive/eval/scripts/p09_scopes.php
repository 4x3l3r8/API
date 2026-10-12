<?php
$cands = [\App\Models\User::class, \EscolaLms\Auth\Models\User::class, \EscolaLms\Core\Models\User::class, \EscolaLms\Courses\Models\User::class, \EscolaLms\Courses\Models\Course::class, \EscolaLms\CourseAccess\Models\Course::class];
foreach ($cands as $c) {
    echo str_pad($c, 48) . (class_exists($c) ? (count((new $c)->getGlobalScopes()) . ' scope(s)') : 'missing') . ' table=' . (class_exists($c) ? (new $c)->getTable() : '-') . PHP_EOL;
}
echo "auth guard provider model: " . config('auth.providers.users.model') . PHP_EOL;
echo "api guard provider: " . config('auth.guards.api.provider') . PHP_EOL;
echo "head of app/Models/User.php: " . trim(preg_replace('/\s+/', ' ', substr(file_get_contents(base_path('app/Models/User.php')), 0, 260))) . PHP_EOL;
