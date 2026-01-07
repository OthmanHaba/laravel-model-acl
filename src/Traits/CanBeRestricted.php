<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Traits;

use YourVendor\LaravelModelAcl\Models\AccessRule;
use YourVendor\LaravelModelAcl\Models\AccessRuleAssignment;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Trait for users/roles that can have access rules assigned to them
 */
trait CanBeRestricted
{
    /**
     * Get all rule assignments for this user/role
     */
    public function ruleAssignments(): MorphMany
    {
        return $this->morphMany(AccessRuleAssignment::class, 'assignable');
    }

    /**
     * Get all access rules assigned to this user/role
     */
    public function assignedAccessRules(): MorphToMany
    {
        return $this->morphToMany(
            AccessRule::class,
            'assignable',
            config('access-control.tables.assignments', 'access_rule_assignments')
        )->withTimestamps();
    }

    /**
     * Assign an access rule to this user/role
     *
     * @param AccessRule|int $rule
     * @return void
     */
    public function assignAccessRule($rule): void
    {
        $ruleId = $rule instanceof AccessRule ? $rule->id : $rule;

        AccessRuleAssignment::firstOrCreate([
            'access_rule_id' => $ruleId,
            'assignable_type' => static::class,
            'assignable_id' => $this->id,
        ]);
    }

    /**
     * Remove an access rule from this user/role
     *
     * @param AccessRule|int $rule
     * @return void
     */
    public function removeAccessRule($rule): void
    {
        $ruleId = $rule instanceof AccessRule ? $rule->id : $rule;

        AccessRuleAssignment::where('access_rule_id', $ruleId)
            ->where('assignable_type', static::class)
            ->where('assignable_id', $this->id)
            ->delete();
    }

    /**
     * Sync access rules for this user/role
     *
     * @param array $ruleIds
     * @return void
     */
    public function syncAccessRules(array $ruleIds): void
    {
        $this->assignedAccessRules()->sync($ruleIds);
    }

    /**
     * Check if user can perform action on a model
     *
     * @param string $action
     * @param mixed $model
     * @return bool
     */
    public function canAccess(string $action, $model): bool
    {
        return app(\YourVendor\LaravelModelAcl\Services\AccessControlService::class)
            ->can($this, $action, $model);
    }

    /**
     * Check if user has a specific access rule assigned
     *
     * @param AccessRule|int $rule
     * @return bool
     */
    public function hasAccessRule($rule): bool
    {
        $ruleId = $rule instanceof AccessRule ? $rule->id : $rule;

        return $this->assignedAccessRules()->where('access_rules.id', $ruleId)->exists();
    }
}
