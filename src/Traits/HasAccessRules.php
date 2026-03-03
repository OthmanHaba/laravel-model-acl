<?php

declare(strict_types=1);

namespace OthmanHaba\LaravelModelAcl\Traits;

use OthmanHaba\LaravelModelAcl\Models\AccessRule;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Trait for models that have access rules applied to them (e.g., Ticket, Post)
 */
trait HasAccessRules
{
    /**
     * Get all access rules for this model
     */
    public function accessRules(): MorphMany
    {
        return $this->morphMany(AccessRule::class, 'ruleable');
    }

    /**
     * Get the resolution logic for this model
     * Override this method in your model to customize
     */
    public function getAccessResolutionLogic(): string
    {
        $modelClass = static::class;
        $modelConfig = config("access-control.models.{$modelClass}", []);

        return $modelConfig['resolution_logic'] ?? config('access-control.default_resolution', 'any');
    }

    /**
     * Get the scope grouping strategy for this model
     * Override this method in your model to customize
     */
    public function getScopeGroupingStrategy(): string
    {
        $modelClass = static::class;
        $modelConfig = config("access-control.models.{$modelClass}", []);

        return $modelConfig['scope_grouping'] ?? config('access-control.default_scope_grouping', 'and');
    }

    /**
     * Get the action prefix for this model's rules
     * Override this method in your model to customize
     */
    public function getActionPrefix(): string
    {
        $modelClass = static::class;
        $modelConfig = config("access-control.models.{$modelClass}", []);

        return $modelConfig['action_prefix'] ?? strtolower(class_basename($this));
    }

    /**
     * Determine if policies should be integrated
     * Override this method in your model to customize
     */
    public function shouldIntegrateWithPolicies(): bool
    {
        $modelClass = static::class;
        $modelConfig = config("access-control.models.{$modelClass}", []);

        return $modelConfig['integrate_with_policies'] ?? config('access-control.integrations.laravel_policies', true);
    }

    /**
     * Scope to filter by user's access rules
     *
     * @param mixed $query
     * @param mixed $user
     * @param string $action
     * @return mixed
     */
    public function scopeAccessibleBy($query, $user, string $action)
    {
        return app(\OthmanHaba\LaravelModelAcl\Services\AccessControlService::class)
            ->filterQuery($user, $action, $query);
    }
}
