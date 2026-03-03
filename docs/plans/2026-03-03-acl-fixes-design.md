# Laravel Model ACL — Fixes & Improvements Design

## Context

Code review identified 22 issues across 4 priority levels. This design addresses all of them.

## Decisions

- **Namespace**: `OthmanHaba\LaravelModelAcl`
- **Default access when no rules exist**: Deny all (both `can()` and `filterQuery()`)
- **Action key matching**: Exact match (`where('key', $action)`) instead of LIKE

---

## P0 — Critical Bugs

### 1. Infinite recursion in HasAccessRules

**Problem**: `getAccessResolutionLogic()` and 3 other methods check `$this instanceof Authorizable` then call themselves.

**Fix**: Remove the `instanceof` checks. The trait provides defaults that read from config. If a model implements `Authorizable`, it overrides the method directly — no delegation in the trait.

### 2. Deny rules ignored in query filtering

**Problem**: `applyScopes()` skips deny rules entirely, so denied records still appear in query results.

**Fix**: After applying allow scopes, iterate deny rules and wrap each in `$query->whereNot(fn($q) => $rule->scope($q, $user))` to exclude denied records.

### 3. Arbitrary class instantiation from database

**Problem**: `rule_class` from the DB is passed directly to `app()->makeWith()`. No validation.

**Fix**: Add `allowed_rule_classes` array in config (seeded with 4 built-in rules). Validate before instantiation. Add `AccessControlService::registerRuleClass(string $class)` for runtime registration of custom rules.

### 4. Zero tests

**Fix**: Full test suite with Orchestra Testbench:
- `Rules/StatusRuleTest` — passes() and scope() with various statuses, enums
- `Rules/DateRangeRuleTest` — from/to/both/neither/null dates
- `Rules/OwnershipRuleTest` — match/mismatch, custom columns
- `Rules/AttributeRuleTest` — all operators, user vs static values
- `Services/RuleResolverTest` — any/all/priority logic, deny rules
- `Services/AccessControlServiceTest` — can(), filterQuery(), no rules, caching
- `Integration/GateIntegrationTest` — Gate::before behavior
- `Traits/HasAccessRulesTest` — scope, config resolution
- `Traits/CanBeRestrictedTest` — assign/remove/sync/check

---

## P1 — Important Fixes

### 5. Type mismatch (Authenticatable vs Model)

**Fix**: Change `getRulesForAssignable()` signature to `Authenticatable|Model $assignable`. Access `$assignable->getKey()` instead of `$assignable->id` for broader compatibility.

### 6. Caching not implemented

**Fix**: Wrap `getRulesForAssignable()` result in `Cache::remember()`:
- Key: `{prefix}:{assignable_type}:{assignable_id}:{action}:{model_class}`
- TTL from config
- Add model observers on `AccessRule` and `AccessRuleAssignment` to flush related cache entries on create/update/delete

### 7. scopeForAction LIKE wildcard bug

**Fix**: Change `scopeForAction` to exact match: `$query->where('key', $action)`. The `ruleable_type` already scopes to the model, so the key only needs the action name.

### 8. Inconsistent no-rules behavior

**Fix**: Both `can()` and `filterQuery()` return deny/empty when no rules exist. Remove the `applyFallbackFiltering()` method entirely. `filterQuery()` will return `$query->whereRaw('1 = 0')` when no rules apply (returns no results).

---

## P2 — Missing Features

### 9. Events

Add two events dispatched from `can()`:
- `AccessGranted { $user, $action, $model, $matchedRules }`
- `AccessDenied { $user, $action, $model, $rules }`

### 10. Middleware

`AccessControlMiddleware` registered as `acl`:
- Usage: `Route::middleware('acl:view,ticket')`
- Applies `filterQuery` for index routes
- Applies `can` check for resource routes with model binding

### 11. N+1 on Spatie roles

**Fix**: Collect all role IDs via `$user->roles->pluck('id')`, then single query with `whereIn('assignable_id', $roleIds)->where('assignable_type', $roleClass)`.

### 12. Loose comparison in OwnershipRule

**Fix**: Cast both values to string before comparison: `(string) $ownerId === (string) $userId`.

---

## P3 — Minor Fixes

### 13. Null date handling

Return `false` in `DateRangeRule::passes()` if model date is null.

### 14. Fallback method_exists bug

Removed entirely — covered by P1 #8 (remove fallback filtering).

### 15. Config key structure

Use model aliases instead of FQCN:
```php
'models' => [
    'ticket' => [
        'class' => \App\Models\Ticket::class,
        'resolution_logic' => 'any',
    ],
],
```

Lookup helper resolves class to config section.
