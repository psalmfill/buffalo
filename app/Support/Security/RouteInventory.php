<?php

namespace App\Support\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Inventory of every registered route and how it is protected (Phase 8B.6).
 *
 * Authentication: the `auth` middleware (or Horizon's own, or an explicit
 * public allow-list entry). Authorisation is detected statically, in order:
 *
 * 1. `can:` middleware on the route;
 * 2. Horizon's Authenticate middleware (the `viewHorizon` gate);
 * 3. in the controller action, or a method of the same controller it calls
 *    (followed transitively): `$this->authorize(...)`, `Gate::authorize|
 *    allows|denies|check|inspect(...)`, `->can(...)` / `->cannot(...)`, or a
 *    permission check (`->checkPermissionTo(...)`). The first match is
 *    reported;
 * 4. a Form Request parameter whose `authorize()` is more than `return true`;
 * 5. an explicit self-service allow-list entry: signed-in users acting only
 *    on their own account, session or notifications, or a data-free
 *    redirect.
 *
 * Self-service entries are checked first, so a self-service route is listed
 * as such even when its controller also calls a gate.
 *
 * A route matching none of these has no detected authorisation; the
 * inventory test fails on it.
 */
final class RouteInventory
{
    /**
     * Public routes: no sign-in, by design. Keyed by "METHOD uri".
     *
     * @var array<string, string>
     */
    public const PUBLIC = [
        'GET /' => 'Redirects to sign-in or the dashboard',
        'GET up' => 'Health check (no data)',
        'GET health/detailed' => 'Detailed health JSON: bearer token (BUFFALO_HEALTH_TOKEN), internal networks only (nginx), no intelligence content',
        'GET login' => 'Sign-in form',
        'POST login' => 'Sign-in (throttled, lockout)',
        'GET two-factor-challenge' => 'TOTP challenge after the password step',
        'POST two-factor-challenge' => 'TOTP challenge (throttled)',
        'GET forgot-password' => 'Password reset request form',
        'POST forgot-password' => 'Password reset request (same response for every address)',
        'GET reset-password/{token}' => 'Password reset form (token checked on submit)',
        'POST reset-password' => 'Password reset (token required)',
        'GET account/setup/{token}' => 'Account setup (single-use token, throttled)',
        'POST account/setup/{token}' => 'Account setup (single-use token, throttled)',
        'GET|POST broadcasting/auth' => 'Broadcast channel auth: authenticates the session and runs the channel callbacks in routes/channels.php',
        'POST csp-report' => 'CSP violation reports from browsers (throttled; logs directive and origin only)',
    ];

    /**
     * Signed-in, self-service routes: they act only on the signed-in user's
     * own account, session, preferences or notifications. Keyed by route
     * name.
     *
     * @var array<string, string>
     */
    public const SELF_SERVICE = [
        'dashboard' => 'Own dashboard; every widget queries through the user\'s scopes',
        'logout' => 'Signs the user out',
        'session.ping' => 'Keeps the user\'s own session alive (no data)',
        'search.index' => 'Global search: every provider queries through the user\'s own scopes and permissions',
        'search.quick' => 'Global search: every provider queries through the user\'s own scopes and permissions',
        'notifications.index' => 'Own notifications only',
        'notifications.recent' => 'Own notifications only',
        'notifications.read' => 'Own notifications only (looked up among the user\'s notifications)',
        'notifications.read-all' => 'Own notifications only',
        'step-up.show' => 'Step-up TOTP prompt for the user\'s own session',
        'step-up.store' => 'Step-up TOTP check for the user\'s own session',
        'security.edit' => 'Own security settings',
        'user-password.update' => 'Own password (current password required)',
        'appearance.edit' => 'Own display preference',
        'two-factor.setup' => 'Own mandatory two-factor setup',
        'two-factor.setup.complete' => 'Own mandatory two-factor setup',
        'two-factor.enable' => 'Own two-factor (password confirmation required)',
        'two-factor.confirm' => 'Own two-factor (password confirmation required)',
        'two-factor.disable' => 'Always refused: two-factor is mandatory (App\\Actions\\Fortify\\DisableTwoFactorAuthentication)',
        'two-factor.qr-code' => 'Own two-factor (password confirmation required)',
        'two-factor.secret-key' => 'Own two-factor (password confirmation required)',
        'two-factor.recovery-codes' => 'Own two-factor (password confirmation required)',
        'two-factor.regenerate-recovery-codes' => 'Own two-factor (password confirmation required)',
        'password.confirm' => 'Confirms the user\'s own password',
        'password.confirm.store' => 'Confirms the user\'s own password',
        'password.confirmation' => 'Own password confirmation status',
        'communities.import.restart' => 'Redirect only (no data)',
    ];

    /**
     * Signed-in redirects with no data and no name. Keyed by "METHOD uri".
     *
     * @var array<string, string>
     */
    public const REDIRECTS = [
        'GET persons/search' => 'Redirect to the persons list (a reload after a search POST)',
        'GET|POST|PUT|PATCH|DELETE|OPTIONS settings' => 'Redirect to settings/security',
    ];

    private const AUTH_CALLS = '/\$this->authorize(?:Resource)?\s*\(|\bGate::(?:authorize|allows|denies|check|inspect|any|none)\s*\(|->(?:can|cannot|canAny|checkPermissionTo|hasPermissionTo)\s*\(/';

    public function __construct(private readonly Router $router) {}

    /**
     * @return list<array{method: string, uri: string, name: string|null, action: string, middleware: list<string>, authentication: string, authorisation: string|null}>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $methods = implode('|', array_values(array_diff($route->methods(), ['HEAD'])));
            $middleware = array_values(array_map(fn ($m) => is_string($m) ? $m : get_debug_type($m), $route->gatherMiddleware()));
            $key = "{$methods} {$route->uri()}";
            $public = self::PUBLIC[$key] ?? null;

            $rows[] = [
                'method' => $methods,
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'action' => $this->actionName($route),
                'middleware' => $middleware,
                'authentication' => $this->authentication($middleware, $public),
                'authorisation' => $public !== null ? 'Public: '.$public : $this->authorisation($route, $middleware),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['uri'], $a['method']] <=> [$b['uri'], $b['method']]);

        return $rows;
    }

    /** @param list<string> $middleware */
    private function authentication(array $middleware, ?string $public): string
    {
        foreach ($middleware as $m) {
            if ($m === 'auth' || str_starts_with($m, 'auth:') || $m === 'Illuminate\Auth\Middleware\Authenticate') {
                return 'auth';
            }
            if ($m === 'Laravel\Horizon\Http\Middleware\Authenticate') {
                return 'horizon';
            }
        }

        return $public !== null ? 'public' : 'none';
    }

    /** @param list<string> $middleware */
    private function authorisation(Route $route, array $middleware): ?string
    {
        foreach ($middleware as $m) {
            if (str_starts_with($m, 'can:')) {
                return "Middleware {$m}";
            }
            if ($m === 'Laravel\Horizon\Http\Middleware\Authenticate') {
                return 'Horizon gate viewHorizon (system.horizon)';
            }
        }

        $name = $route->getName();
        if ($name !== null && isset(self::SELF_SERVICE[$name])) {
            return 'Self-service: '.self::SELF_SERVICE[$name];
        }

        $key = implode('|', array_values(array_diff($route->methods(), ['HEAD']))).' '.$route->uri();
        if (isset(self::REDIRECTS[$key])) {
            return 'Redirect: '.self::REDIRECTS[$key];
        }

        [$class, $method] = $this->controller($route);
        if ($class === null || ! method_exists($class, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($class, $method);
        $calls = $this->authorisationCalls($reflection);
        if ($calls !== null) {
            return $calls;
        }

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class) && method_exists($type->getName(), 'authorize')) {
                $authorize = new ReflectionMethod($type->getName(), 'authorize');
                $body = $this->source($authorize);
                if ($authorize->getDeclaringClass()->getName() !== FormRequest::class && preg_match('/return\s+true\s*;/', $body) !== 1) {
                    return 'Form Request '.class_basename($type->getName()).'::authorize()';
                }
            }
        }

        return null;
    }

    /**
     * Authorisation calls in the action or the controller methods it calls.
     */
    private function authorisationCalls(ReflectionMethod $action): ?string
    {
        $class = $action->getDeclaringClass();
        $seen = [];
        $queue = [$action->getName()];

        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name]) || ! $class->hasMethod($name)) {
                continue;
            }
            $seen[$name] = true;
            $method = $class->getMethod($name);
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }
            $body = $this->source($method);

            if (preg_match(self::AUTH_CALLS, $body, $m) === 1) {
                return $name === $action->getName()
                    ? 'Controller: '.trim($m[0], " (\t\n")
                    : 'Controller: '.trim($m[0], " (\t\n")." (in {$name}())";
            }

            preg_match_all('/\$this->(\w+)\s*\(|self::(\w+)\s*\(|static::(\w+)\s*\(/', $body, $calls);
            foreach (array_filter([...$calls[1], ...$calls[2], ...$calls[3]]) as $called) {
                $queue[] = $called;
            }
        }

        return null;
    }

    private function source(ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        if ($file === false) {
            return '';
        }

        $lines = file($file) ?: [];

        return implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));
    }

    /** @return array{0: class-string|null, 1: string} */
    private function controller(Route $route): array
    {
        $uses = $route->getAction('uses');
        if (! is_string($uses)) {
            return [null, ''];
        }

        [$class, $method] = str_contains($uses, '@') ? explode('@', $uses, 2) : [$uses, '__invoke'];

        /** @var class-string|null $class */
        $class = class_exists($class) ? $class : null;

        return [$class, $method];
    }

    private function actionName(Route $route): string
    {
        $uses = $route->getAction('uses');
        if (! is_string($uses)) {
            return 'Closure';
        }

        return str_starts_with($uses, 'App\\') ? substr($uses, 4) : $uses;
    }

    /**
     * Markdown for docs/security/route-inventory.md.
     *
     * @param  list<array{method: string, uri: string, name: string|null, action: string, middleware: list<string>, authentication: string, authorisation: string|null}>  $rows
     */
    public static function markdown(array $rows): string
    {
        $shown = fn (array $middleware) => implode(', ', array_map(
            fn (string $m) => str_contains($m, '\\') ? class_basename($m) : $m,
            array_values(array_filter($middleware, fn (string $m) => $m !== 'web')),
        ));
        $cell = fn (string $value) => str_replace('|', '\|', $value);

        $out = "# Route inventory\n\n"
            ."Generated by `php artisan buffalo:security:route-inventory` (Phase 8B.6). Do not edit by hand:\n"
            ."`tests/Feature/Security/RouteInventoryTest.php` fails when this file is out of date, and when any route\n"
            ."lacks authentication or a detected authorisation check. How authorisation is detected is described in\n"
            ."`app/Support/Security/RouteInventory.php`. Every route also passes through the `web` middleware group.\n\n"
            .'Routes: '.count($rows)."\n\n"
            ."| Method | URI | Name | Action | Middleware | Authentication | Authorisation |\n"
            ."|---|---|---|---|---|---|---|\n";

        foreach ($rows as $row) {
            $out .= '| '.implode(' | ', array_map($cell, [
                $row['method'], '`'.$row['uri'].'`', $row['name'] ?? '', $row['action'], $shown($row['middleware']),
                $row['authentication'], $row['authorisation'] ?? '**NONE**',
            ]))." |\n";
        }

        return $out;
    }
}
