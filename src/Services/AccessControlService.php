<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Services;

use YourVendor\LaravelModelAcl\Contracts\AccessRuleContract;
use YourVendor\LaravelModelAcl\Contracts\RuleResolverContract;
use YourVendor\LaravelModelAcl\Contracts\Authorizable;
use YourVendor\LaravelModelAcl\Models\AccessRule;
use YourVendor\LaravelModelAcl\Models\AccessRuleAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
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
        $rules = $this->getApplicableRules($user, $action, $model);

        if ($rules->isEmpty()) {
            return false;
        }

        // Get resolution logic from model or config
        $resolutionLogic = $this->getResolutionLogic($model);

        // Instantiate rule classes
        $ruleInstances = $this->instantiateRules($rules, $user);

        // Use resolver to determine access
        return $this->ruleResolver->resolve($ruleInstances, $user, $model, $resolutionLogic);
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

        // Get rules from user's roles (if using Spatie Permission)
        $roleRules = collect();
        if (config('access-control.integrations.spatie_permission') && method_exists($user, 'roles')) {
            foreach ($user->roles as $role) {
                $roleRules = $roleRules->merge(
                    $this->getRulesForAssignable($role, $action, $modelClass)
                );
            }
        }

        return $userRules->merge($roleRules)->unique('id');
    }

    /**
     * Get rules for a specific assignable (user, role, etc.)
     *
     * @param mixed $assignable
     * @param string $action
     * @param string $modelClass
     * @return Collection
     */
    protected function getRulesForAssignable($assignable, string $action, string $modelClass): Collection
    {
        return AccessRule::query()
            ->active()
            ->forAction($action)
            ->forModel($modelClass)
            ->whereHas('assignments', function ($query) use ($assignable) {
                $query->where('assignable_type', get_class($assignable))
                      ->where('assignable_id', $assignable->id);
            })
            ->orderedByPriority()
            ->get();
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

            $class = app()->makeWith($rule->rule_class, $params);

            if (!$class instanceof AccessRuleContract) {
                throw new \RuntimeException(
                    "Rule class {$rule->rule_class} must implement AccessRuleContract"
                );
            }

            return $class;
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
        if ($groupingStrategy === 'and') {
            // Apply all scopes with AND logic (restrictive)
            foreach ($ruleInstances as $rule) {
                if (method_exists($rule, 'scope') && !$rule->isDenyRule()) {
                    $query = $rule->scope($query, $user);
                }
            }
        } elseif ($groupingStrategy === 'or') {
            // Apply scopes with OR logic (additive) - group them properly
            $query->where(function ($q) use ($ruleInstances, $user) {
                $first = true;
                foreach ($ruleInstances as $rule) {
                    if (method_exists($rule, 'scope') && !$rule->isDenyRule()) {
                        if ($first) {
                            $rule->scope($q, $user);
                            $first = false;
                        } else {
                            $q->orWhere(function ($subQuery) use ($rule, $user) {
                                $rule->scope($subQuery, $user);
                            });
                        }
                    }
                }
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

        // Check if model has a default fallback
        if (isset($fallbackConfig[$modelClass])) {
            $column = $fallbackConfig[$modelClass]['column'] ?? 'user_id';
            return $query->where($column, $user->id);
        }

        // Default: restrict to owned records if user_id column exists
        if (method_exists($query->getModel(), 'user_id')) {
            return $query->where('user_id', $user->id);
        }

        return $query;
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
