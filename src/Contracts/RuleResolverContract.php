<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

interface RuleResolverContract
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
    ): bool;

    /**
     * Sort rules by priority
     *
     * @param Collection $rules
     * @return Collection
     */
    public function sortByPriority(Collection $rules): Collection;
}
