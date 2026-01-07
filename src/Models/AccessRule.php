<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessRule extends Model
{
    protected $fillable = [
        'name',
        'key',
        'rule_class',
        'settings',
        'ruleable_type',
        'ruleable_id',
        'priority',
        'is_deny_rule',
        'active',
    ];

    protected $casts = [
        'settings' => 'array',
        'priority' => 'integer',
        'is_deny_rule' => 'boolean',
        'active' => 'boolean',
    ];

    /**
     * Get the table name from config
     */
    public function getTable(): string
    {
        return config('access-control.tables.access_rules', 'access_rules');
    }

    /**
     * Polymorphic relationship to the model this rule applies to
     * NULL means it's a global rule
     */
    public function ruleable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get all assignments (users/roles) for this rule
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(AccessRuleAssignment::class);
    }

    /**
     * Scope to get active rules only
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope to get rules by action key
     */
    public function scopeForAction($query, string $action)
    {
        return $query->where('key', 'like', "{$action}_%");
    }

    /**
     * Scope to get rules for a specific model type
     */
    public function scopeForModel($query, string $modelClass)
    {
        return $query->where(function ($q) use ($modelClass) {
            $q->where('ruleable_type', $modelClass)
              ->orWhereNull('ruleable_type'); // Include global rules
        });
    }

    /**
     * Order by priority (higher first)
     */
    public function scopeOrderedByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }
}
