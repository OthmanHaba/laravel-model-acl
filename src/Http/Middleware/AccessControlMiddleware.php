<?php

declare(strict_types=1);

namespace OthmanHaba\LaravelModelAcl\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use OthmanHaba\LaravelModelAcl\Traits\HasAccessRules;

/**
 * Route middleware: `Route::middleware('acl:view')` or `acl:view,ticket`.
 *
 * Enforces a per-record check against a route-bound model. Index routes (no bound
 * model) can't be filtered from middleware — use the `accessibleBy` scope in the
 * controller for those.
 */
class AccessControlMiddleware
{
    public function handle(Request $request, Closure $next, string $action, ?string $param = null)
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $model = $this->resolveModel($request, $param);

        if ($model !== null && !$user->can($action, $model)) {
            abort(403);
        }

        return $next($request);
    }

    protected function resolveModel(Request $request, ?string $param): ?Model
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        // Explicit parameter name given: acl:view,ticket
        if ($param !== null) {
            $value = $route->parameter($param);

            return $value instanceof Model ? $value : null;
        }

        // Otherwise use the first bound model managed by this package.
        foreach ($route->parameters() as $value) {
            if ($value instanceof Model && $this->isManaged($value)) {
                return $value;
            }
        }

        return null;
    }

    protected function isManaged(Model $model): bool
    {
        return in_array(HasAccessRules::class, class_uses_recursive($model), true);
    }
}
