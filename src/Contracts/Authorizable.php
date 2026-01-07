<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

interface Authorizable
{
    /**
     * Get the resolution logic for this model
     * Options: 'any', 'all', 'priority'
     *
     * @return string
     */
    public function getAccessResolutionLogic(): string;

    /**
     * Get the scope grouping strategy for queries
     * Options: 'and', 'or'
     *
     * @return string
     */
    public function getScopeGroupingStrategy(): string;

    /**
     * Get the action prefix for this model's rules
     *
     * @return string
     */
    public function getActionPrefix(): string;

    /**
     * Determine if policies should be integrated
     *
     * @return bool
     */
    public function shouldIntegrateWithPolicies(): bool;
}
