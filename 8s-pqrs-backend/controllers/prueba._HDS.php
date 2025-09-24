
<?php

Tipos de Middlewares en Laravel
1. Middleware Global
Se ejecuta en todas las peticiones HTTP.
php<?php

// app/Http/Middleware/TrustProxies.php
class TrustProxies implements Middleware
{
    public function handle($request, Closure $next)
    {
        // Se ejecuta para TODAS las peticiones
        $request->setTrustedProxies(['192.168.1.1'], Request::HEADER_X_FORWARDED_ALL);
        
        return $next($request);
    }
}

// app/Http/Kernel.php
protected $middleware = [
    \App\Http\Middleware\TrustProxies::class,
    \App\Http\Middleware\CheckForMaintenanceMode::class,
    // ... otros middlewares globales
];

2. Middleware de Grupo
Se aplican a grupos específicos de rutas (web, api).
php<?php
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
        'throttle:api',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ],
];
3. Middleware de Ruta
Se aplican a rutas específicas.
php<?php
// routes/web.php
Route::get('/admin', [AdminController::class, 'index'])
    ->middleware('auth', 'admin', 'verified');

// Con middleware inline
Route::get('/profile', function () {
    return view('profile');
})->middleware(function ($request, $next) {
    if ($request->user()->isBlocked()) {
        return redirect('/blocked');
    }
    return $next($request);
});
Middlewares Esenciales de Laravel
1. Autenticación (Auth)
php<?php
// app/Http/Middleware/Authenticate.php
class Authenticate extends Middleware
{
    public function handle($request, Closure $next, ...$guards)
    {
        $this->authenticate($request, $guards);
        
        return $next($request);
    }

    protected function authenticate($request, array $guards)
    {
        if (empty($guards)) {
            $guards = [null];
        }

        foreach ($guards as $guard) {
            if ($this->auth->guard($guard)->check()) {
                return $this->auth->shouldUse($guard);
            }
        }

        $this->unauthenticated($request, $guards);
    }
}

// Uso
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('auth');
2. Autorización Personalizada
php<?php
// app/Http/Middleware/AdminMiddleware.php
class AdminMiddleware
{
    public function handle($request, Closure $next)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            abort(403, 'Access denied. Admin privileges required.');
        }

        return $next($request);
    }
}

// app/Http/Kernel.php
protected $routeMiddleware = [
    'admin' => \App\Http\Middleware\AdminMiddleware::class,
];

// Uso
Route::prefix('admin')->middleware('admin')->group(function () {
    Route::get('/users', [AdminController::class, 'users']);
    Route::get('/reports', [AdminController::class, 'reports']);
});
3. Rate Limiting (Throttle)
php<?php
// Uso básico
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/api/send-message', [MessageController::class, 'send']);
});

// Throttle personalizado
Route::post('/api/upload', [UploadController::class, 'store'])
    ->middleware('throttle:5,1'); // 5 peticiones por minuto

// Throttle por usuario
Route::middleware('auth', 'throttle:rate_limit,1')->group(function () {
    // rate_limit viene del modelo User
});
4. CORS (Cross-Origin Resource Sharing)
php<?php
// app/Http/Middleware/CorsMiddleware.php
class CorsMiddleware
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');

        return $response;
    }
}
5. Logging y Auditoría
php<?php
// app/Http/Middleware/LogRequests.php
class LogRequests
{
    public function handle($request, Closure $next)
    {
        // Log antes de la petición
        Log::info('Request started', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => $request->user()?->id,
        ]);

        $response = $next($request);

        // Log después de la respuesta
        Log::info('Request completed', [
            'status_code' => $response->getStatusCode(),
            'response_time' => microtime(true) - LARAVEL_START,
        ]);

        return $response;
    }
}
Middlewares Avanzados y Casos de Uso
1. Middleware con Parámetros
php<?php
// app/Http/Middleware/CheckRole.php
class CheckRole
{
    public function handle($request, Closure $next, ...$roles)
    {
        if (!$request->user()) {
            return redirect('login');
        }

        foreach ($roles as $role) {
            if ($request->user()->hasRole($role)) {
                return $next($request);
            }
        }

        abort(403, 'Insufficient permissions');
    }
}

// Registro
protected $routeMiddleware = [
    'role' => \App\Http\Middleware\CheckRole::class,
];

// Uso con parámetros
Route::get('/admin/users', [UserController::class, 'index'])
    ->middleware('role:admin,super-admin');
2. Middleware de Validación
php<?php
// app/Http/Middleware/ValidateApiKey.php
class ValidateApiKey
{
    public function handle($request, Closure $next)
    {
        $apiKey = $request->header('X-API-Key');

        if (!$apiKey || !$this->isValidApiKey($apiKey)) {
            return response()->json([
                'error' => 'Invalid or missing API key'
            ], 401);
        }

        // Agregar información del API key al request
        $request->merge(['api_client' => $this->getApiClient($apiKey)]);

        return $next($request);
    }

    private function isValidApiKey($key): bool
    {
        return ApiKey::where('key', $key)
                   ->where('active', true)
                   ->where('expires_at', '>', now())
                   ->exists();
    }
}
3. Middleware de Cache
php<?php
// app/Http/Middleware/CacheResponse.php
class CacheResponse
{
    public function handle($request, Closure $next, $minutes = 60)
    {
        $key = 'response:' . md5($request->fullUrl());

        // Verificar si existe en cache
        if (Cache::has($key)) {
            return response(Cache::get($key));
        }

        $response = $next($request);

        // Solo cachear respuestas exitosas
        if ($response->getStatusCode() === 200) {
            Cache::put($key, $response->getContent(), now()->addMinutes($minutes));
        }

        return $response;
    }
}

// Uso
Route::get('/api/products', [ProductController::class, 'index'])
    ->middleware('cache:30'); // Cachear por 30 minutos
4. Middleware de Transformación de Datos
php<?php
// app/Http/Middleware/TransformJsonResponse.php
class TransformJsonResponse
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if ($response->headers->get('content-type') === 'application/json') {
            $data = json_decode($response->getContent(), true);
            
            $transformed = [
                'success' => true,
                'data' => $data,
                'timestamp' => now()->toISOString(),
                'version' => '1.0'
            ];

            $response->setContent(json_encode($transformed));
        }

        return $response;
    }
}
5. Middleware de Mantenimiento Personalizado
php<?php
// app/Http/Middleware/MaintenanceMode.php
class MaintenanceMode
{
    public function handle($request, Closure $next)
    {
        if ($this->isInMaintenanceMode() && !$this->hasMaintenanceAccess($request)) {
            return response()->view('maintenance', [], 503);
        }

        return $next($request);
    }

    private function isInMaintenanceMode(): bool
    {
        return Cache::get('maintenance_mode', false);
    }

    private function hasMaintenanceAccess($request): bool
    {
        // Permitir acceso por IP o token especial
        $allowedIPs = ['127.0.0.1', '192.168.1.100'];
        $maintenanceToken = $request->get('maintenance_token');

        return in_array($request->ip(), $allowedIPs) || 
               $maintenanceToken === config('app.maintenance_token');
    }
}
Patrones Avanzados con Middlewares
1. Middleware Terminable
php<?php
// app/Http/Middleware/PerformanceLogger.php
class PerformanceLogger
{
    private $startTime;

    public function handle($request, Closure $next)
    {
        $this->startTime = microtime(true);
        
        return $next($request);
    }

    // Se ejecuta después de enviar la respuesta al cliente
    public function terminate($request, $response)
    {
        $executionTime = microtime(true) - $this->startTime;
        
        Log::info('Performance metrics', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'status_code' => $response->getStatusCode(),
        ]);

        // Enviar métricas a servicio externo si es necesario
        if ($executionTime > 2.0) { // Más de 2 segundos
            $this->alertSlowRequest($request, $executionTime);
        }
    }
}
2. Middleware Condicional
php<?php
// app/Http/Middleware/ConditionalMiddleware.php
class ConditionalMiddleware
{
    public function handle($request, Closure $next)
    {
        // Solo aplicar en producción
        if (app()->environment('production')) {
            return $this->applyProductionLogic($request, $next);
        }

        // Solo aplicar para ciertos usuarios
        if ($request->user()?->isVip()) {
            return $this->applyVipLogic($request, $next);
        }

        return $next($request);
    }
}
3. Middleware con Dependencias
php<?php
// app/Http/Middleware/DatabaseConnectionMiddleware.php
class DatabaseConnectionMiddleware
{
    protected $connectionManager;
    protected $logger;

    public function __construct(DatabaseManager $db, Logger $logger)
    {
        $this->connectionManager = $db;
        $this->logger = $logger;
    }

    public function handle($request, Closure $next)
    {
        // Seleccionar conexión de BD basada en el tenant
        $tenant = $request->header('X-Tenant-ID');
        
        if ($tenant) {
            $this->connectionManager->setDefaultConnection("tenant_{$tenant}");
        }

        return $next($request);
    }
}
Mejores Prácticas para Middlewares
1. Principio de Responsabilidad Única
php// ✅ Correcto - Una responsabilidad
class AuthenticateMiddleware
{
    public function handle($request, Closure $next)
    {
        // Solo maneja autenticación
        if (!Auth::check()) {
            return redirect('login');
        }
        return $next($request);
    }
}

// ❌ Incorrecto - Múltiples responsabilidades
class AuthAndLoggingMiddleware
{
    public function handle($request, Closure $next)
    {
        // Maneja autenticación Y logging
        if (!Auth::check()) {
            Log::warning('Unauthorized access attempt');
            return redirect('login');
        }
        Log::info('User accessed resource');
        return $next($request);
    }
}
2. Manejo de Errores
php<?php
class SafeMiddleware
{
    public function handle($request, Closure $next)
    {
        try {
            // Lógica del middleware
            $this->performSecurityCheck($request);
            
            return $next($request);
            
        } catch (SecurityException $e) {
            Log::warning('Security check failed', ['exception' => $e->getMessage()]);
            return response()->json(['error' => 'Access denied'], 403);
            
        } catch (Exception $e) {
            Log::error('Middleware error', ['exception' => $e]);
            return response()->json(['error' => 'Internal error'], 500);
        }
    }
}
3. Performance y Eficiencia
php<?php
class EfficientMiddleware
{
    public function handle($request, Closure $next)
    {
        // Cache resultados costosos
        $cacheKey = 'middleware:' . $request->user()?->id;
        
        $result = Cache::remember($cacheKey, 300, function () use ($request) {
            return $this->expensiveOperation($request);
        });

        if (!$result['allowed']) {
            return response('Forbidden', 403);
        }

        return $next($request);
    }
}
4. Testing de Middlewares
php<?php
// tests/Unit/Middleware/AdminMiddlewareTest.php
class AdminMiddlewareTest extends TestCase
{
    public function test_allows_admin_user()
    {
        $admin = User::factory()->admin()->create();
        $request = Request::create('/admin', 'GET');
        $request->setUserResolver(fn() => $admin);

        $middleware = new AdminMiddleware();
        $response = $middleware->handle($request, fn($req) => response('OK'));

        $this->assertEquals('OK', $response->getContent());
    }

    public function test_blocks_non_admin_user()
    {
        $user = User::factory()->create();
        $request = Request::create('/admin', 'GET');
        $request->setUserResolver(fn() => $user);

        $middleware = new AdminMiddleware();

        $this->expectException(HttpException::class);
        $middleware->handle($request, fn($req) => response('OK'));
    }
}
Casos de Uso Reales y Ejemplos
1. E-commerce: Verificación de Stock
php<?php
class CheckProductStock
{
    public function handle($request, Closure $next)
    {
        if ($request->route('product')) {
            $product = $request->route('product');
            
            if ($product->stock <= 0) {
                return redirect()->route('products.index')
                    ->with('error', 'Product is out of stock');
            }
        }

        return $next($request);
    }
}
2. SaaS: Verificación de Límites de Plan
php<?php
class CheckPlanLimits
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();
        
        if ($user->hasExceededPlanLimits()) {
            return response()->json([
                'error' => 'Plan limits exceeded',
                'upgrade_url' => route('billing.upgrade')
            ], 402); // Payment Required
        }

        return $next($request);
    }
}
3. Multi-tenant: Selección de Base de Datos
php<?php
class TenantDatabaseMiddleware
{
    public function handle($request, Closure $next)
    {
        $subdomain = $request->getHost();
        $tenant = Tenant::where('domain', $subdomain)->first();

        if (!$tenant) {
            abort(404, 'Tenant not found');
        }

        // Cambiar conexión de BD
        config(['database.connections.mysql.database' => $tenant->database]);
        DB::reconnect('mysql');

        // Agregar tenant al request
        $request->merge(['tenant' => $tenant]);

        return $next($request);
    }
}
Impacto en el Rendimiento
Middlewares y Performance
php// ❌ Middleware ineficiente
class SlowMiddleware
{
    public function handle($request, Closure $next)
    {
        // Consulta costosa en cada request
        $settings = Setting::all();
        
        // Operación lenta
        sleep(1);
        
        return $next($request);
    }
}

// ✅ Middleware optimizado
class FastMiddleware
{
    public function handle($request, Closure $next)
    {
        // Cache de settings
        $settings = Cache::remember('app_settings', 3600, function () {
            return Setting::all();
        });
        
        // Operación asíncrona si es posible
        dispatch(new LogRequestJob($request));
        
        return $next($request);
    }
}
Conclusión: El Rol Fundamental de los Middlewares
Los Middlewares son ESENCIALES en Laravel porque:

Arquitectura Limpia: Separan responsabilidades y mantienen controladores enfocados
Seguridad Robusta: Implementan capas de seguridad de forma consistente
Reutilización: Un middleware se aplica a múltiples rutas sin duplicar código
Flexibilidad: Permiten interceptar y modificar requests/responses
Mantenibilidad: Centralizan lógica transversal en un solo lugar
Testing: Son fáciles de testear de forma aislada

Casos de Uso Críticos:

Autenticación y Autorización
Rate Limiting y Throttling
Logging y Auditoría
Validación de APIs
Transformación de Datos
Cache de Respuestas
CORS para APIs

Recomendaciones Finales:

Úsalos siempre para lógica transversal
Mantén el principio de responsabilidad única
Optimiza el rendimiento con cache y operaciones eficientes
Testéalos exhaustivamente
Documenta su propósito claramente

Puntuación de Importancia: 5/5 ⭐⭐⭐⭐⭐
Los middlewares son una pieza fundamental de Laravel que permite crear aplicaciones seguras, mantenibles y bien estructuradas. Sin ellos, sería imposible manejar aspectos como seguridad, logging y validación de forma elegante y reutilizable.