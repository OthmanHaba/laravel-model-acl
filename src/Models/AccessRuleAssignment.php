<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AccessRuleAssignment extends Model
{
    protected $fillable = [
        'access_rule_id',
        'assignable_type',
        'assignable_id',
    ];

    /**
     * Get the table name from config
     */
    public function getTable(): string
    {
        return config('access-control.tables.assignments', 'access_rule_assignments');
    }

    /**
     * Get the access rule
     */
    public function accessRule(): BelongsTo
    {
        return $this->belongsTo(AccessRule::class);
    }

    /**
     * Polymorphic relationship to the assignable (User, Employee, Role, etc.)
     */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to get assignments for a specific user/role
     */
    public function scopeForAssignable($query, string $type, int $id)
    {
        return $query->where('assignable_type', $type)
                     ->where('assignable_id', $id);
    }
}
