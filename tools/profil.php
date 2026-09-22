<?php
// Profilage : temps + nombre de requêtes SQL par page (php tools/profil.php). Ne modifie rien (lecture seule).
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$pages = [
    ['visiteur', null, '/'],
    ['visiteur', null, '/prestations'],
    ['client', 'demo.client@koudmain.test', '/client'],
    ['client', 'demo.client@koudmain.test', '/client/catalogue'],
    ['client', 'demo.client@koudmain.test', '/client/commandes'],
    ['client', 'demo.client@koudmain.test', '/client/wallet'],
    ['client', 'demo.client@koudmain.test', '/messages'],
    ['client', 'demo.client@koudmain.test', '/notifications'],
    ['presta', 'demo.mariam@koudmain.test', '/prestataire'],
    ['presta', 'demo.mariam@koudmain.test', '/prestataire/commandes'],
    ['presta', 'demo.mariam@koudmain.test', '/prestataire/prestations'],
    ['admin', 'admin.demo@koudmain.test', '/admin'],
    ['admin', 'admin.demo@koudmain.test', '/admin/commandes'],
    ['client', 'demo.client@koudmain.test', '/temps-reel/sonder'],
];
$n = (int) ($argv[1] ?? 3);
printf("%-10s %-32s %6s %7s %7s\n", 'rôle', 'page', 'ms', 'requêtes', 'sql ms');
foreach ($pages as [$role, $email, $uri]) {
    $best = null;
    for ($i = 0; $i < $n; $i++) {
        $app = require __DIR__.'/../bootstrap/app.php';
        Illuminate\Support\Facades\Facade::clearResolvedInstances();
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $kernel->bootstrap();
        $count = 0; $sql = 0.0; $liste = [];
        DB::listen(function ($q) use (&$count, &$sql, &$liste) { $count++; $sql += $q->time; $origine = ''; foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $f) { if (isset($f['file']) && str_contains($f['file'], '/app/') || isset($f['file']) && str_contains($f['file'], '/storage/framework/views/')) { $origine = basename($f['file']).':'.($f['line'] ?? ''); break; } } $liste[] = [$q->time, $q->sql.'  << '.$origine]; });
        $request = Request::create($uri, 'GET');
        $app->instance('request', $request);
        if ($email) {
            $u = App\Models\User::where('email', $email)->first();
            $app['auth']->guard('web')->setUser($u);
        }
        $t = microtime(true);
        $resp = $kernel->handle($request);
        $ms = (microtime(true) - $t) * 1000;
        if ($best === null || $ms < $best[0]) $best = [$ms, $count, $sql, $resp->getStatusCode(), $liste];
    }
    printf("%-10s %-32s %6.0f %7d %7.1f  [%d]\n", $role, $uri, $best[0], $best[1], $best[2], $best[3]);
    if (getenv('SQL') && $uri === getenv('SQL')) foreach ($best[4] as [$tm, $s]) printf("   %5.2f  %s\n", $tm, substr($s, 0, 60).' ... '.substr($s, strpos($s,'<<')));
}
