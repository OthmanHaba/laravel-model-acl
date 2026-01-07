<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;

interface AccessRuleContract
{
    /**
     * Check if the rule passes for a specific model instance
     *
     * @param Authenticatable $user The user/employee/role being checked
     * @param Model $model The model instance being accessed
     * @return bool
     */
    public function passes(Authenticatable $user, Model $model): bool;

    /**
     * Apply query scope for filtering collections
     *
     * @param mixed $query The query builder instance
     * @param Authenticatable $user The user/employee/role being checked
     * @return mixed The modified query
     */
    public function scope($query, Authenticatable $user);

    /**
     * Get the priority/weight of this rule (higher = executed first)
     *
     * @return int
     */
    public function getPriority(): int;

    /**
     * Determine if this is a deny rule (negative rule)
     *
     * @return bool
     */
    public function isDenyRule(): bool;
}
