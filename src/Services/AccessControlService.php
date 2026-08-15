<?php

declare(strict_types=1);

namespace OthmanHaba\LaravelModelAcl\Services;

use OthmanHaba\LaravelModelAcl\Contracts\AccessRuleContract;
use OthmanHaba\LaravelModelAcl\Contracts\RuleResolverContract;
use OthmanHaba\LaravelModelAcl\Contracts\Authorizable;
use OthmanHaba\LaravelModelAcl\Models\AccessRule;
use OthmanHaba\LaravelModelAcl\Models\AccessRuleAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Builder;

class AccessControlService
{
    public function __construct(
        protected RuleResolverContract $ruleResolver
    ) {}

    /**
     * Check if a user can perform an action on a specific model instance
     *
     * @param Authenticatable $user
     * @param string $action
     * @param Model $model
     * @return bool
     */
    public function can(Authenticatable $user, string $action, Model $model): bool
    {
        // No applicable rules => deny by default for direct checks.
        return $this->decide($user, $action, $model) === true;
    }

    /**
     * Resolve access as a tri-state so callers (e.g. Gate::before) can tell
     * "explicitly denied" (false) apart from "this package has no opinion" (null).
     *
     * @return bool|null true = grant, false = deny, null = no applicable rules
     */
    public function decide(Authenticatable $user, string $action, Model $model): ?bool
    {
        $rules = $this->getApplicableRules($user, $action, $model);

        if ($rules->isEmpty()) {
            return null;
        }

        $resolutionLogic = $this->getResolutionLogic($model);
        $ruleInstances = $this->instantiateRules($rules, $user);

        $granted = $this->ruleResolver->resolve($ruleInstances, $user, $model, $resolutionLogic);

        event($granted
            ? new \OthmanHaba\LaravelModelAcl\Events\AccessGranted($user, $action, $model, $ruleInstances)
            : new \OthmanHaba\LaravelModelAcl\Events\AccessDenied($user, $action, $model, $ruleInstances));

        return $granted;
    }

    /**
     * Filter a query based on user's access rules
     *
     * @param Authenticatable $user
     * @param string $action
     * @param Builder|null $initialQuery
     * @param string|null $modelClass
     * @return Builder
     */
    public function filterQuery(
        Authenticatable $user,
        string $action,
        ?Builder $initialQuery = null,
        ?string $modelClass = null
    ): Builder {
        // Determine model class from query or parameter
        if ($initialQuery) {
            $modelClass = get_class($initialQuery->getModel());
        }

        if (!$modelClass) {
            throw new \InvalidArgumentException('Model class must be provided or derivable from query');
        }

        $query = $initialQuery ?? $modelClass::query();

        $rules = $this->getApplicableRules($user, $action, new $modelClass);

        if ($rules->isEmpty()) {
            // Apply fallback logic (e.g., only show owned records)
            return $this->applyFallbackFiltering($query, $user, $modelClass);
        }

        // Get scope grouping strategy
        $groupingStrategy = $this->getScopeGroupingStrategy(new $modelClass);

        // Instantiate rule classes
        $ruleInstances = $this->instantiateRules($rules, $user);

        // Apply scopes based on grouping strategy
        return $this->applyScopes($query, $ruleInstances, $user, $groupingStrategy);
    }

    /**
     * Get applicable rules for a user, action, and model
     *
     * @param Authenticatable $user
     * @param string $action
     * @param Model $model
     * @return Collection
     */
    protected function getApplicableRules(Authenticatable $user, string $action, Model $model): Collection
    {
        $modelClass = get_class($model);

        // Get user's directly assigned rules
        $userRules = $this->getRulesForAssignable($user, $action, $modelClass);

        // Get rules from user's roles (if using Spatie Permission) in ONE query
        $roleRules = collect();
        if (config('access-control.integrations.spatie_permission') && method_exists($user, 'roles')) {
            $roles = $user->roles;
            if ($roles->isNotEmpty()) {
                $roleRules = $this->getRulesForAssignables(
                    get_class($roles->first()),
                    $roles->pluck('id')->all(),
                    $action,
                    $modelClass
                );
            }
        }

        return $userRules->merge($roleRules)->unique('id');
    }

    /**
     * Get rules for a specific assignable (user, role, etc.)
     *
     * @param Model $assignable
     * @param string $action
     * @param string $modelClass
     * @return Collection
     */
    protected function getRulesForAssignable(Model $assignable, string $action, string $modelClass): Collection
    {
        return $this->getRulesForAssignables(
            get_class($assignable),
            [$assignable->getKey()],
            $action,
            $modelClass
        );
    }

    /**
     * Fetch active rules assigned to any of the given assignable ids in a single
     * query. Result is cached (invalidated on rule/assignment writes) when caching
     * is enabled.
     */
    protected function getRulesForAssignables(string $type, array $ids, string $action, string $modelClass): Collection
    {
        if (empty($ids)) {
            return collect();
        }

        $fetch = fn() => AccessRule::query()
            ->active()
            ->forAction($action)
            ->forModel($modelClass)
            ->whereHas('assignments', function ($query) use ($type, $ids) {
                $query->where('assignable_type', $type)
                      ->whereIn('assignable_id', $ids);
            })
            ->orderedByPriority()
            ->get();

        if (!config('access-control.cache.enabled', false)) {
            return $fetch();
        }

        $prefix = config('access-control.cache.prefix', 'access_control');
        $version = (int) Cache::get($prefix . ':version', 0);
        sort($ids);
        $key = $prefix . ':v' . $version . ':' . md5($type . '|' . implode(',', $ids) . '|' . $action . '|' . $modelClass);

        return Cache::remember($key, (int) config('access-control.cache.ttl', 3600), $fetch);
    }

    /**
     * Invalidate all cached rule lookups. Cheap: bumps a version counter that is
     * baked into every cache key, so old entries become unreachable and expire via
     * TTL. Works on every cache store (no tag support required).
     */
    public static function flushCache(): void
    {
        if (!config('access-control.cache.enabled', false)) {
            return;
        }

        $key = config('access-control.cache.prefix', 'access_control') . ':version';
        Cache::forever($key, (int) Cache::get($key, 0) + 1);
    }

    /**
     * Instantiate rule classes with their settings
     *
     * @param Collection $rules
     * @param Authenticatable $user
     * @return Collection
     */
    protected function instantiateRules(Collection $rules, Authenticatable $user): Collection
    {
        return $rules->map(function (AccessRule $rule) use ($user) {
            $params = $rule->settings ?? [];

            // Inject user context if the rule needs it
            $params['_user'] = $user;
            $params['_priority'] = $rule->priority;
            $params['_is_deny_rule'] = $rule->is_deny_rule;

            // Validate BEFORE instantiating: never construct an arbitrary
            // class name pulled from the database.
            if (!is_subclass_of($rule->rule_class, AccessRuleContract::class)) {
                throw new \RuntimeException(
                    "Rule class {$rule->rule_class} must implement AccessRuleContract"
                );
            }

            return app()->makeWith($rule->rule_class, $params);
        });
    }

    /**
     * Apply scopes to query based on grouping strategy
     *
     * @param Builder $query
     * @param Collection $ruleInstances
     * @param Authenticatable $user
     * @param string $groupingStrategy
     * @return Builder
     */
    protected function applyScopes(
        Builder $query,
        Collection $ruleInstances,
        Authenticatable $user,
        string $groupingStrategy
    ): Builder {
        $allowRules = $ruleInstances->filter(fn($rule) => !$rule->isDenyRule());
        $denyRules = $ruleInstances->filter(fn($rule) => $rule->isDenyRule());

        // No allow rules => nothing is granted. Mirrors can()/resolver where a
        // deny-only rule set never grants access.
        if ($allowRules->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        if ($groupingStrategy === 'or') {
            // OR logic (additive) - group the allow scopes together
            $query->where(function ($q) use ($allowRules, $user) {
                $first = true;
                foreach ($allowRules as $rule) {
                    if ($first) {
                        $rule->scope($q, $user);
                        $first = false;
                    } else {
                        $q->orWhere(function ($subQuery) use ($rule, $user) {
                            $rule->scope($subQuery, $user);
                        });
                    }
                }
            });
        } else {
            // AND logic (restrictive)
            foreach ($allowRules as $rule) {
                $query = $rule->scope($query, $user);
            }
        }

        // Deny rules always subtract their matched set, regardless of grouping.
        foreach ($denyRules as $rule) {
            $query->whereNot(function ($q) use ($rule, $user) {
                $rule->scope($q, $user);
            });
        }

        return $query;
    }

    /**
     * Apply fallback filtering when no rules exist
     *
     * @param Builder $query
     * @param Authenticatable $user
     * @param string $modelClass
     * @return Builder
     */
    protected function applyFallbackFiltering(Builder $query, Authenticatable $user, string $modelClass): Builder
    {
        $fallbackConfig = config('access-control.fallback', []);

        // Check if model has a configured fallback column
        if (isset($fallbackConfig[$modelClass])) {
            $column = $fallbackConfig[$modelClass]['column'] ?? 'user_id';
            return $query->where($column, $user->getAuthIdentifier());
        }

        // No rules and no fallback configured => deny by default (return nothing).
        return $query->whereRaw('1 = 0');
    }

    /**
     * Get resolution logic for a model
     *
     * @param Model $model
     * @return string
     */
    protected function getResolutionLogic(Model $model): string
    {
        if ($model instanceof Authorizable) {
            return $model->getAccessResolutionLogic();
        }

        $modelClass = get_class($model);
        $modelConfig = config("access-control.models.{$modelClass}", []);

        return $modelConfig['resolution_logic'] ?? config('access-control.default_resolution', 'any');
    }

    /**
     * Get scope grouping strategy for a model
     *
     * @param Model $model
     * @return string
     */
    protected function getScopeGroupingStrategy(Model $model): string
    {
        if ($model instanceof Authorizable) {
            return $model->getScopeGroupingStrategy();
        }

        $modelClass = get_class($model);
        $modelConfig = config("access-control.models.{$modelClass}", []);

        return $modelConfig['scope_grouping'] ?? config('access-control.default_scope_grouping', 'and');
    }
}
