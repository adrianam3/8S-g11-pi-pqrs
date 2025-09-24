
<?php

Tipos de Middlewares en Laravel (adaptados a PQRS + Encuestas)
1) Middleware Global

Se ejecuta en todas las peticiones (útil para proxy corporativo, trazabilidad, cabeceras comunes).

<?php
// app/Http/Middleware/TrustProxies.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class TrustProxies
{
    public function handle($request, Closure $next)
    {
        // Imbauto: confiar en el balanceador/reverse proxy interno
        $request->setTrustedProxies(
            ['10.0.0.0/8', '192.168.0.0/16'],
            SymfonyRequest::HEADER_X_FORWARDED_ALL
        );

        // Adjuntar un X-Request-Id si no viene del gateway (para auditoría de PQRS)
        if (!$request->headers->has('X-Request-Id')) {
            $request->headers->set('X-Request-Id', (string) \Str::uuid());
        }

        return $next($request);
    }
}

// app/Http/Kernel.php
protected $middleware = [
    \App\Http\Middleware\TrustProxies::class,
    \Illuminate\Http\Middleware\HandleCors::class,
    \App\Http\Middleware\PreventRequestsDuringMaintenance::class, // Laravel 10+
    // otros globales...
];

2) Middleware de Grupo

Configura stacks distintos para web y api. En API añadimos throttle específico para PQRS y bindings.

<?php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'web' => [
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
    ],

    'api' => [
        'throttle:pqrs', // limitador definido por nosotros (ver abajo)
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ],
];


Define el limitador en RouteServiceProvider:

// app/Providers/RouteServiceProvider.php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

public function boot()
{
    RateLimiter::for('pqrs', function (Request $request) {
        $key = $request->user()?->id ?: $request->ip();
        // Ej.: 30 req/min para usuarios autenticados, 10 para anónimos
        return $request->user()
            ? Limit::perMinute(30)->by($key)
            : Limit::perMinute(10)->by($key);
    });
}

3) Middleware de Ruta

Se aplican a rutas específicas (roles, verificación de usuarios bloqueados, etc.).

<?php
// routes/api.php
use App\Http\Controllers\PqrsController;
use App\Http\Controllers\EncuestaController;

Route::middleware(['auth:sanctum'])->group(function () {
    // Solo admin o agente gestiona PQRS
    Route::get('/pqrs', [PqrsController::class, 'index'])->middleware('role:admin,agente');
    Route::post('/pqrs', [PqrsController::class, 'store'])->middleware('role:admin,agente');
    Route::post('/pqrs/{id}/cerrar', [PqrsController::class, 'cerrar'])->middleware('role:admin,agente');

    // Usuario final responde encuestas
    Route::get('/encuestas/mias', [EncuestaController::class, 'pendientesPorUsuario'])->middleware('role:usuario');
    Route::post('/encuestas/responder', [EncuestaController::class, 'responder'])
        ->middleware(['role:usuario','throttle:20,1']); // evitar abuso
});

// Ejemplo inline: bloquear usuarios suspendidos (p.ej., abuso de PQRS)
Route::get('/estado-cuenta', function () {
    return response()->json(['ok'=>true]);
})->middleware(function ($request, $next) {
    if ($request->user()?->is_suspended) {
        return response()->json(['error'=>'Cuenta suspendida'], 423);
    }
    return $next($request);
});

Middlewares Esenciales (versión PQRS/Encuestas)
1) Autenticación (Sanctum)
<?php
// app/Http/Middleware/Authenticate.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Base;

class Authenticate extends Base
{
    public function handle($request, Closure $next, ...$guards)
    {
        $this->authenticate($request, $guards);
        return $next($request);
    }
}

// Uso
Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])
    ->middleware('auth:sanctum');

2) Autorización personalizada (roles)
<?php
// app/Http/Middleware/RoleMiddleware.php
namespace App\Http\Middleware;

use Closure;

class RoleMiddleware
{
    public function handle($request, Closure $next, ...$roles)
    {
        $u = $request->user();
        if (!$u || !in_array($u->rol, $roles)) {
            abort(403, 'Acceso denegado');
        }
        return $next($request);
    }
}

// app/Http/Kernel.php
protected $routeMiddleware = [
    'role' => \App\Http\Middleware\RoleMiddleware::class,
];

3) Rate Limiting (Throttle)

Aplicado ya vía grupo o ruta:

// creación de PQRS y respuesta de encuestas
Route::post('/pqrs', [PqrsController::class, 'store'])->middleware('throttle:15,1');
Route::post('/encuestas/responder', [EncuestaController::class, 'responder'])->middleware('throttle:20,1');

4) CORS (Angular ↔ Laravel API)
<?php
// app/Http/Middleware/CorsMiddleware.php
namespace App\Http\Middleware;

use Closure;

class CorsMiddleware
{
    public function handle($request, Closure $next)
    {
        $allowed = env('FRONTEND_ORIGIN', 'https://app.imbauto.com');
        $response = $next($request);

        $response->headers->set('Access-Control-Allow-Origin', $allowed);
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Request-Id');

        return $response;
    }
}

5) Logging y Auditoría (PQRS)
<?php
// app/Http/Middleware/LogPqrsRequests.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;

class LogPqrsRequests
{
    public function handle($request, Closure $next)
    {
        Log::info('API IN', [
            'rid' => $request->header('X-Request-Id'),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'uid' => $request->user()?->id,
            'ip' => $request->ip(),
        ]);

        $response = $next($request);

        Log::info('API OUT', [
            'rid' => $request->header('X-Request-Id'),
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}

Middlewares Avanzados y Casos de Uso (en clave PQRS/Encuestas)
1) Middleware con Parámetros (roles)
// Uso con parámetros
Route::get('/admin/estadisticas', [\App\Http\Controllers\Admin\KpiController::class, 'index'])
    ->middleware('role:admin,gerente');

2) Middleware de Validación (API Key interna)

Para integraciones (ej. canal de envío de encuestas por SMS/Email desde un job o un microservicio interno).

<?php
// app/Http/Middleware/ValidateApiKey.php
namespace App\Http\Middleware;

use Closure;
use App\Models\ApiKey;

class ValidateApiKey
{
    public function handle($request, Closure $next)
    {
        $apiKey = $request->header('X-API-Key');

        $valid = $apiKey && ApiKey::where('key', $apiKey)
            ->where('active', true)
            ->where('expires_at', '>', now())
            ->exists();

        if (!$valid) {
            return response()->json(['error' => 'API key inválida'], 401);
        }

        return $next($request);
    }
}

3) Middleware de Cache (KPIs/Dashboard)
<?php
// app/Http/Middleware/CacheResponse.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;

class CacheResponse
{
    public function handle($request, Closure $next, $minutes = 5)
    {
        $key = 'resp:'.md5($request->fullUrl());

        if (Cache::has($key)) {
            return response(Cache::get($key), 200, ['X-Cache-Hit' => '1']);
        }

        $response = $next($request);

        if ($response->getStatusCode() === 200 && $request->is('api/dashboard/*')) {
            Cache::put($key, $response->getContent(), now()->addMinutes($minutes));
        }

        return $response;
    }
}

// Uso:
Route::get('/api/dashboard/indicadores', [\App\Http\Controllers\DashboardController::class,'indicadores'])
    ->middleware('cache:5'); // 5 minutos

4) Middleware de Transformación de JSON (envelope común)
<?php
// app/Http/Middleware/ApiEnvelope.php
namespace App\Http\Middleware;

use Closure;

class ApiEnvelope
{
    public function handle($request, Closure $next)
    {
        $res = $next($request);

        if (str_contains($res->headers->get('content-type'), 'application/json')) {
            $payload = json_decode($res->getContent(), true);

            $res->setContent(json_encode([
                'success' => $res->getStatusCode() < 400,
                'data' => $payload,
                'timestamp' => now()->toISOString(),
                'version' => '1.0',
                'request_id' => $request->header('X-Request-Id')
            ], JSON_UNESCAPED_UNICODE));
        }

        return $res;
    }
}

5) Mantenimiento Personalizado

Permite a admin o IPs internas acceder durante mantenimiento (útil para cerrar temporalmente encuestas públicas).

<?php
// app/Http/Middleware/MaintenanceMode.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;

class MaintenanceMode
{
    public function handle($request, Closure $next)
    {
        $on = Cache::get('maintenance_mode', false);

        if ($on && !$this->hasBypass($request)) {
            return response()->json(['message'=>'En mantenimiento'], 503);
        }
        return $next($request);
    }

    private function hasBypass($request): bool
    {
        $allowedIPs = ['127.0.0.1', '192.168.1.100'];
        return in_array($request->ip(), $allowedIPs) || $request->user()?->rol === 'admin';
    }
}

Patrones avanzados con Middlewares
1) Terminable (métricas de performance de PQRS)
<?php
// app/Http/Middleware/PerformanceLogger.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;

class PerformanceLogger
{
    private float $start;

    public function handle($request, Closure $next)
    {
        $this->start = microtime(true);
        return $next($request);
    }

    public function terminate($request, $response)
    {
        $time = (microtime(true) - $this->start) * 1000; // ms
        if ($request->is('api/pqrs*') || $request->is('api/encuestas*')) {
            Log::info('Perf', [
                'url' => $request->fullUrl(),
                'ms'  => round($time, 1),
                'status' => $response->getStatusCode(),
                'uid' => $request->user()?->id,
            ]);
        }
    }
}

2) Condicional (solo producción / VIP)
<?php
// app/Http/Middleware/ConditionalMiddleware.php
namespace App\Http\Middleware;

use Closure;

class ConditionalMiddleware
{
    public function handle($request, Closure $next)
    {
        if (app()->environment('production') && $request->is('api/dashboard/*')) {
            // Lógica extra en prod para KPIs sensibles
        }

        if ($request->user()?->rol === 'gerente') {
            // Por ejemplo, ampliar límites de paginación en KPIs
            $request->merge(['per_page' => min(100, (int) $request->get('per_page', 50))]);
        }

        return $next($request);
    }
}

3) Con Dependencias (multi-agencia/tenant por cabecera)
<?php
// app/Http/Middleware/AgencyConnection.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\DatabaseManager as DB;

class AgencyConnection
{
    public function __construct(private DB $db) {}

    public function handle($request, Closure $next)
    {
        // Cambiar conexión por agencia (si tu arquitectura es multi-DB)
        if ($agency = $request->header('X-Agency-ID')) {
            config(['database.connections.mysql.database' => "imbauto_{$agency}"]);
            $this->db->reconnect('mysql');
            $request->attributes->set('agency_id', $agency);
        }
        return $next($request);
    }
}

Mejores Prácticas
1) Responsabilidad Única
// ✅ Solo autenticación
class AuthenticateMiddleware {
    public function handle($request, \Closure $next) {
        if (!auth()->check()) return response()->json(['error'=>'Unauthenticated'], 401);
        return $next($request);
    }
}

2) Manejo de Errores
class SafeMiddleware {
    public function handle($request, \Closure $next) {
        try {
            // ...
            return $next($request);
        } catch (\DomainException $e) {
            return response()->json(['error'=>$e->getMessage()], 403);
        } catch (\Throwable $e) {
            return response()->json(['error'=>'Internal error'], 500);
        }
    }
}

3) Performance

Cachea operaciones costosas (KPIs).

Evita consultas N+1 en middlewares.

Lleva logging pesado a jobs (colas).

Testing de Middlewares (adaptado)
<?php
// tests/Unit/Middleware/RoleMiddlewareTest.php
use Tests\TestCase;
use Illuminate\Http\Request;
use App\Http\Middleware\RoleMiddleware;
use App\Models\User;

class RoleMiddlewareTest extends TestCase
{
    public function test_bloquea_usuario_sin_rol_requerido()
    {
        $user = User::factory()->create(['rol'=>'usuario']);
        $req = Request::create('/api/pqrs', 'GET');
        $req->setUserResolver(fn() => $user);

        $mw = new RoleMiddleware();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $mw->handle($req, fn($r)=>response('OK'), 'admin','agente');
    }

    public function test_permite_agente()
    {
        $user = User::factory()->create(['rol'=>'agente']);
        $req = Request::create('/api/pqrs', 'GET');
        $req->setUserResolver(fn() => $user);

        $mw = new RoleMiddleware();
        $res = $mw->handle($req, fn($r)=>response('OK'), 'admin','agente');
        $this->assertEquals('OK', $res->getContent());
    }
}

Casos de Uso Reales (versión Imbauto)
1) Evitar PQRS duplicadas recientes (misma orden/cliente)
<?php
// app/Http/Middleware/AvoidDuplicatePqrs.php
namespace App\Http\Middleware;

use Closure;
use App\Models\Pqrs;

class AvoidDuplicatePqrs
{
    public function handle($request, Closure $next)
    {
        $exists = Pqrs::where('id_cliente', $request->id_cliente)
            ->where('asunto', $request->asunto)
            ->where('created_at', '>', now()->subHours(24))
            ->exists();

        if ($exists) {
            return response()->json(['error'=>'Ya existe una PQR similar reciente'], 429);
        }

        return $next($request);
    }
}

2) Límite por plan/rol (ej. usuarios que crean demasiadas PQRS)
<?php
// app/Http/Middleware/CheckPqrsRateForUser.php
namespace App\Http\Middleware;

use Closure;
use App\Models\Pqrs;

class CheckPqrsRateForUser
{
    public function handle($request, Closure $next)
    {
        $count = Pqrs::where('id_cliente', $request->user()->id_cliente)
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($count >= 5) {
            return response()->json(['error' => 'Límite de PQRS por hora alcanzado'], 429);
        }
        return $next($request);
    }
}

3) Multi-tenant por subdominio (agencia)
<?php
// app/Http/Middleware/TenantBySubdomain.php
namespace App\Http\Middleware;

use Closure;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class TenantBySubdomain
{
    public function handle($request, Closure $next)
    {
        $host = $request->getHost(); // p.ej., quito.app.imbauto.com
        $sub = explode('.', $host)[0];

        $tenant = Tenant::where('domain', $sub)->firstOrFail();
        config(['database.connections.mysql.database' => $tenant->database]);
        DB::reconnect('mysql');

        $request->attributes->set('tenant', $tenant);
        return $next($request);
    }
}

Impacto en Rendimiento (mal vs bien)
// ❌ Evitar: consultas pesadas en cada request
class SlowMiddleware {
    public function handle($request, \Closure $next) {
        $settings = \App\Models\Setting::all(); // Costo alto en cada request
        sleep(1);
        return $next($request);
    }
}

// ✅ Mejor: cache + trabajo async
class FastMiddleware {
    public function handle($request, \Closure $next) {
        $settings = cache()->remember('settings', 3600, fn()=>\App\Models\Setting::all());
        dispatch(new \App\Jobs\LogRequestJob($request->header('X-Request-Id')));
        return $next($request);
    }
}

Conclusión: el rol fundamental de los middlewares (en Imbauto)

Arquitectura limpia: centralizan lógica transversal y mantienen controladores enfocados.

Seguridad robusta: autentican (Sanctum), autorizan (roles/policies) y limitan abuso (throttle/rate).

Reutilización: un mismo middleware protege múltiples rutas (/api/pqrs, /api/encuestas).

Flexibilidad: transforman requests/responses, cachean KPIs y habilitan mantenimiento controlado.

Mantenibilidad & testing: se testean de forma aislada y facilitan CI/CD.

Puntuación de importancia: 5/5 ⭐⭐⭐⭐⭐
Para la API de PQRS & Encuestas de Imbauto, los middlewares son la primera línea de defensa y el punto de control que garantiza seguridad, orden y rendimiento, habilitando una plataforma estable y escalable para el negocio.