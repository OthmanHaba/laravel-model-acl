<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Services;

use YourVendor\LaravelModelAcl\Contracts\RuleResolverContract;
use YourVendor\LaravelModelAcl\Contracts\AccessRuleContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

class RuleResolver implements RuleResolverContract
{
    /**
     * Resolve whether access should be granted based on multiple rules
     *
     * @param Collection $rules Collection of AccessRuleContract instances
     * @param Authenticatable $user
     * @param Model $model
     * @param string $resolutionLogic 'any', 'all', or 'priority'
     * @return bool
     */
    public function resolve(
        Collection $rules,
        Authenticatable $user,
        Model $model,
        string $resolutionLogic = 'any'
    ): bool {
        if ($rules->isEmpty()) {
            return false;
        }

        // Sort by priority first
        $sortedRules = $this->sortByPriority($rules);

        return match ($resolutionLogic) {
            'any' => $this->resolveAny($sortedRules, $user, $model),
            'all' => $this->resolveAll($sortedRules, $user, $model),
            'priority' => $this->resolvePriority($sortedRules, $user, $model),
            default => $this->resolveAny($sortedRules, $user, $model),
        };
    }

    /**
     * Sort rules by priority (higher first)
     *
     * @param Collection $rules
     * @return Collection
     */
    public function sortByPriority(Collection $rules): Collection
    {
        return $rules->sortByDesc(function (AccessRuleContract $rule) {
            return $rule->getPriority();
        })->values();
    }

    /**
     * Resolve using ANY logic - any rule passes = granted
     * Deny rules are checked first
     *
     * @param Collection $rules
     * @param Authenticatable $user
     * @param Model $model
     * @return bool
     */
    protected function resolveAny(Collection $rules, Authenticatable $user, Model $model): bool
    {
        // Check deny rules first - if any deny rule passes, access is denied
        $denyRules = $rules->filter(fn(AccessRuleContract $rule) => $rule->isDenyRule());
        foreach ($denyRules as $rule) {
            if ($rule->passes($user, $model)) {
                return false; // Explicitly denied
            }
        }

        // Check allow rules - if any passes, access is granted
        $allowRules = $rules->filter(fn(AccessRuleContract $rule) => !$rule->isDenyRule());
        foreach ($allowRules as $rule) {
            if ($rule->passes($user, $model)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve using ALL logic - all rules must pass
     * Deny rules are checked first
     *
     * @param Collection $rules
     * @param Authenticatable $user
     * @param Model $model
     * @return bool
     */
    protected function resolveAll(Collection $rules, Authenticatable $user, Model $model): bool
    {
        // Check deny rules first - if any deny rule passes, access is denied
        $denyRules = $rules->filter(fn(AccessRuleContract $rule) => $rule->isDenyRule());
        foreach ($denyRules as $rule) {
            if ($rule->passes($user, $model)) {
                return false; // Explicitly denied
            }
        }

        // All allow rules must pass
        $allowRules = $rules->filter(fn(AccessRuleContract $rule) => !$rule->isDenyRule());

        if ($allowRules->isEmpty()) {
            return false;
        }

        foreach ($allowRules as $rule) {
            if (!$rule->passes($user, $model)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve using PRIORITY logic - first matching rule wins
     * Rules are already sorted by priority
     *
     * @param Collection $rules
     * @param Authenticatable $user
     * @param Model $model
     * @return bool
     */
    protected function resolvePriority(Collection $rules, Authenticatable $user, Model $model): bool
    {
        foreach ($rules as $rule) {
            if ($rule->passes($user, $model)) {
                // First match wins - if it's a deny rule, deny; if allow rule, allow
                return !$rule->isDenyRule();
            }
        }

        return false;
    }
}
