# Laravel Model ACL

Database-driven, rule-based access control for **any** Laravel model. Instead of hard-coding
authorization in policies, you store small **rule objects** in the database, assign them to
users (or roles), and the package decides access — both for a single record (`can`) and for a
whole query (`filterQuery`). It plugs into Laravel Gates/Policies and optionally Spatie
Permission.

- **Two sides to every rule** — `passes()` checks one record, `scope()` filters a query. You get consistent allow/deny whether you check `$user->can('view', $ticket)` or list `Ticket::accessibleBy($user, 'view')`.
- **Allow + deny rules** with priority and three resolution strategies (`any` / `all` / `priority`).
- **Deny-by-default** — no matching rule means no access.
- **Caching, events, route middleware, and an artisan generator** included.

---

## Table of contents

1. [How it works (mental model)](#how-it-works-mental-model)
2. [Installation](#installation)
3. [Setup: the two traits](#setup-the-two-traits)
4. [Creating rules](#creating-rules)
5. [The `key` convention (important)](#the-key-convention-important)
6. [Assigning rules](#assigning-rules)
7. [Checking access](#checking-access)
8. [Built-in rules](#built-in-rules)
9. [Writing custom rules](#writing-custom-rules)
10. [Resolution logic & deny rules](#resolution-logic--deny-rules)
11. [Query filtering & scope grouping](#query-filtering--scope-grouping)
12. [Global rules](#global-rules)
13. [Configuration reference](#configuration-reference)
14. [Gate & Policy integration](#gate--policy-integration)
15. [Spatie Permission (roles)](#spatie-permission-roles)
16. [Route middleware](#route-middleware)
17. [Events](#events)
18. [Caching](#caching)
19. [Per-model configuration & the `Authorizable` interface](#per-model-configuration--the-authorizable-interface)
20. [API reference](#api-reference)
21. [Testing](#testing)

---

## How it works (mental model)

There are three moving parts:

| Concept | What it is | Where it lives |
|--------|------------|----------------|
| **Rule class** | A PHP object with `passes()` (per-record) and `scope()` (per-query) logic | `src/Rules/*` or your `App\Rules\Access\*` |
| **AccessRule (DB row)** | A stored configuration: which rule class, its settings, which model, priority, allow/deny | `access_rules` table |
| **Assignment (DB row)** | Links an AccessRule to a user or role | `access_rule_assignments` table |

Flow when you check access:

```
$user->can('view', $ticket)
   → collect the AccessRule rows assigned to $user (and their roles) for action "view" on Ticket
   → instantiate each row's rule class with its settings
   → resolve allow/deny across all of them (any / all / priority)
   → grant, deny, or (if no rules) defer / deny-by-default
```

---

## Installation

```bash
composer require othmanhaba/laravel-model-acl
```

> Local path package? Add a `path` repository to your app's `composer.json` and
> `composer require othmanhaba/laravel-model-acl:@dev`.

Publish the config and migrations, then migrate:

```bash
php artisan vendor:publish --tag=access-control-config
php artisan vendor:publish --tag=access-control-migrations
php artisan migrate
```

The service provider is auto-discovered — no manual registration needed. Migrations also load
automatically from the package, so you can migrate without publishing them if you don't need to
customize.

**Requirements:** PHP 8.1+, Laravel 10 or 11.

---

## Setup: the two traits

There are two roles a model can play. A model can even play both.

**1. Models that are protected** (the thing being accessed — `Ticket`, `Post`, `Invoice`):

```php
use OthmanHaba\LaravelModelAcl\Traits\HasAccessRules;

class Ticket extends Model
{
    use HasAccessRules;
}
```

**2. Models that access things** (users, employees, or Spatie roles):

```php
use OthmanHaba\LaravelModelAcl\Traits\CanBeRestricted;

class User extends Authenticatable
{
    use CanBeRestricted;
}
```

`HasAccessRules` enables Gate integration and the `accessibleBy()` query scope.
`CanBeRestricted` adds `assignAccessRule()`, `canAccess()`, and friends.

---

## Creating rules

An `AccessRule` row points at a rule class and stores its `settings`. Example — "allow viewing
tickets whose status is pending or in_progress":

```php
use OthmanHaba\LaravelModelAcl\Models\AccessRule;
use OthmanHaba\LaravelModelAcl\Rules\StatusRule;

$rule = AccessRule::create([
    'name'          => 'View active tickets',
    'key'           => 'view_ticket',              // see "The key convention" below
    'rule_class'    => StatusRule::class,
    'ruleable_type' => \App\Models\Ticket::class,  // null = applies to every model (global)
    'settings'      => ['statuses' => ['pending', 'in_progress']],
    'priority'      => 10,
    'is_deny_rule'  => false,
    'active'        => true,
]);
```

**How `settings` maps to the rule:** the keys of `settings` are passed to the rule class
constructor **by name**. `StatusRule`'s constructor is `__construct(?array $statuses, ?string
$status_column = 'status', ...)`, so `settings` uses the keys `statuses` and `status_column`.
Each built-in rule's accepted keys are listed under [Built-in rules](#built-in-rules).

---

## The `key` convention (important)

Rules are matched to an action by their `key` column using a **prefix match**: checking action
`view` selects every rule whose key is `LIKE 'view_%'`.

**Therefore the `key` must be `{action}_{anything}`.** Conventionally `{action}_{model}`:

| You check | Rule `key` must start with | Example key |
|-----------|-----------------------------|-------------|
| `'view'`  | `view_`  | `view_ticket` |
| `'update'`| `update_`| `update_post` |
| `'delete'`| `delete_`| `delete_invoice` |

A bare key of `view` (no underscore) will **not** match action `view`. The `ruleable_type`
column already scopes a rule to its model, so the suffix after the action is just for your own
readability.

---

## Assigning rules

Rules do nothing until assigned to a user or role. Use the `CanBeRestricted` methods:

```php
$user->assignAccessRule($rule);      // AccessRule instance or id
$user->removeAccessRule($rule);
$user->syncAccessRules([1, 2, 3]);   // replace all assignments with these rule ids
$user->hasAccessRule($rule);         // bool
$user->assignedAccessRules;          // relation: all rules assigned to this user
```

All three mutating methods flush the rule cache automatically (see [Caching](#caching)).

---

## Checking access

Three interchangeable styles — pick whichever reads best at the call site.

**A. The service**

```php
use OthmanHaba\LaravelModelAcl\Services\AccessControlService;

$acl = app(AccessControlService::class);

$acl->can($user, 'view', $ticket);                     // bool — single record
$acl->filterQuery($user, 'view', Ticket::query())->get(); // Builder — filtered list
```

**B. The trait methods**

```php
$user->canAccess('view', $ticket);          // bool
Ticket::accessibleBy($user, 'view')->get(); // filtered query (needs HasAccessRules)
```

**C. Laravel Gates (automatic)**

```php
$user->can('view', $ticket);   // routed through this package via Gate::before

@can('view', $ticket)
    <a href="...">Open</a>
@endcan
```

> **Deny-by-default:** if no rule applies, `can()` returns `false` and `filterQuery()` returns
> **no rows**. This is intentional for an ACL. Configure a [fallback](#configuration-reference)
> column if you want "owned records" instead of "nothing" when unconfigured.

---

## Built-in rules

All accept the shared trailing constructor args (`_user`, `_priority`, `_is_deny_rule`) which the
package injects for you — you only ever set the domain keys below in `settings`.

### StatusRule
Allow access based on a status column.

| Setting | Default | Meaning |
|--------|---------|---------|
| `statuses` | — | Array of allowed values. Empty/omitted ⇒ no restriction. |
| `status_column` | `status` | Column to read. |

```php
'rule_class' => \OthmanHaba\LaravelModelAcl\Rules\StatusRule::class,
'settings'   => ['statuses' => ['pending', 'approved'], 'status_column' => 'status'],
```
Enum-backed columns are supported (the enum's `->value` is compared).

### DateRangeRule
Allow access when a date column falls in a range.

| Setting | Default | Meaning |
|--------|---------|---------|
| `from` | — | Start (any string Carbon can parse). Uses start-of-day. |
| `to`   | — | End. Uses end-of-day. |
| `date_column` | `created_at` | Column to read. |

```php
'rule_class' => \OthmanHaba\LaravelModelAcl\Rules\DateRangeRule::class,
'settings'   => ['from' => '2024-01-01', 'to' => '2024-12-31'],
```

### OwnershipRule
Restrict to records the user owns.

| Setting | Default | Meaning |
|--------|---------|---------|
| `owner_column` | `user_id` | Column on the **model** holding the owner id. |
| `user_id_column` | `id` | Attribute on the **user** to match against. |

```php
'rule_class' => \OthmanHaba\LaravelModelAcl\Rules\OwnershipRule::class,
'settings'   => ['owner_column' => 'user_id'],
```

### AttributeRule
Compare a model attribute to a user attribute or a static value.

| Setting | Default | Meaning |
|--------|---------|---------|
| `model_attribute` | — | Column on the model (required). |
| `user_attribute` | — | Attribute on the user to compare with. |
| `static_value` | — | A fixed value to compare with (used if `user_attribute` is absent). |
| `operator` | `=` | One of `=`, `!=`, `>`, `>=`, `<`, `<=`, `in`, `not_in`. |

```php
// Match the user's department
'settings' => ['model_attribute' => 'department_id', 'user_attribute' => 'department_id'],

// Only high-priority records
'settings' => ['model_attribute' => 'priority', 'static_value' => 'high', 'operator' => '='],

// One of several
'settings' => ['model_attribute' => 'status', 'static_value' => ['open', 'pending'], 'operator' => 'in'],
```

### FilterRule
Restrict with a list of column/operator/value clauses combined with `and`/`or`.
AND binds tighter than OR (standard SQL precedence), and the same grouping drives
both `passes()` and `scope()` so per-record and per-query checks always agree.

| Setting | Default | Meaning |
|--------|---------|---------|
| `clauses` | `[]` | List of `['column', 'operator', 'value', 'boolean']`. `boolean` (`and`/`or`) joins a clause to the previous one; ignored on the first. |

Operators: `=`, `!=`, `>`, `>=`, `<`, `<=`, `in`, `not_in`, `contains`. For `in`/`not_in`
the value may be an array or a comma-separated string.

```php
'rule_class' => \OthmanHaba\LaravelModelAcl\Rules\FilterRule::class,
// (priority > 3 AND status = open) OR amount <= 100
'settings' => ['clauses' => [
    ['column' => 'priority', 'operator' => '>',  'value' => 3],
    ['column' => 'status',   'operator' => '=',  'value' => 'open', 'boolean' => 'and'],
    ['column' => 'amount',   'operator' => '<=', 'value' => 100,    'boolean' => 'or'],
]],
```

---

## Writing custom rules

Generate a skeleton:

```bash
php artisan make:access-rule TicketDepartment --model=Ticket
# → app/Rules/Access/TicketDepartmentRule.php  ("Rule" suffix added automatically)
```

Implement both methods — `passes()` for single-record checks, `scope()` for query filtering.
Keep them equivalent so `can()` and `filterQuery()` agree:

```php
namespace App\Rules\Access;

use OthmanHaba\LaravelModelAcl\Rules\BaseAccessRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;

class TicketDepartmentRule extends BaseAccessRule
{
    // Constructor params become the `settings` keys (snake_case). The three
    // underscore-prefixed params are injected by the package — keep them last.
    public function __construct(
        protected ?string $department_column = 'department_id',
        ?Authenticatable $_user = null,
        ?int $_priority = null,
        ?bool $_is_deny_rule = null
    ) {
        parent::__construct($_user, $_priority, $_is_deny_rule);
    }

    public function passes(Authenticatable $user, Model $model): bool
    {
        return $user->department_id === $model->{$this->department_column};
    }

    public function scope(Builder $query, Authenticatable $user): Builder
    {
        return $query->where($this->department_column, $user->department_id);
    }
}
```

Register it as an AccessRule row like any built-in:

```php
AccessRule::create([
    'name'          => 'Same department',
    'key'           => 'view_ticket',
    'rule_class'    => \App\Rules\Access\TicketDepartmentRule::class,
    'ruleable_type' => \App\Models\Ticket::class,
    'settings'      => ['department_column' => 'department_id'],
    'active'        => true,
]);
```

> **Security note:** `rule_class` is validated to implement `AccessRuleContract` **before** it is
> instantiated, so a stray/invalid class name in the table throws instead of being constructed.
> Keep write access to the `access_rules` table restricted to trusted admins regardless.

---

## Resolution logic & deny rules

When several rules apply to the same action, the **resolution logic** decides the outcome.
Set it globally (`default_resolution`), [per model](#per-model-configuration--the-authorizable-interface), or via the `Authorizable` interface.

| Logic | Behaviour |
|-------|-----------|
| `any` (default) | Deny rules are checked first — if any deny rule matches, access is **denied**. Otherwise, if **any** allow rule matches, access is **granted**. |
| `all` | Deny rules first (any match ⇒ denied). Then **every** allow rule must match. |
| `priority` | Rules sorted by `priority` desc; the **first** rule that matches wins — if it's a deny rule, denied; if allow, granted. |

**Deny rules** (`is_deny_rule => true`) express "block this even if something else would allow
it." A classic pattern is a high-priority deny plus lower-priority allows:

```php
// Block archived tickets for everyone this is assigned to
AccessRule::create([
    'name' => 'Block archived', 'key' => 'view_ticket',
    'rule_class' => StatusRule::class, 'ruleable_type' => Ticket::class,
    'settings' => ['statuses' => ['archived']],
    'is_deny_rule' => true, 'priority' => 100,
]);
```

> **Deny needs a paired allow.** A rule set containing *only* deny rules grants nothing — allow
> rules define what's visible, deny rules subtract from it. This is consistent across `can()` and
> `filterQuery()`.

---

## Query filtering & scope grouping

`filterQuery()` / `accessibleBy()` build a query by applying each allow rule's `scope()`, then
subtracting each deny rule's matched set with `whereNot`. How the **allow** scopes combine is the
**scope grouping** strategy:

| Grouping | Behaviour |
|----------|-----------|
| `and` (default) | All allow conditions must hold (restrictive intersection). |
| `or` | Any allow condition qualifies a row (additive union). |

Deny rules are **always** applied as an exclusion, regardless of grouping.

```php
// e.g. allow [open, archived] + deny [archived]  ⇒  only "open" rows returned
Ticket::accessibleBy($user, 'view')->paginate();
```

---

## Global rules

Leave `ruleable_type` as `null` to make a rule apply to **every** model type for its action. Model
lookups include both the model-specific rows and the global ones.

```php
AccessRule::create([
    'name' => 'Superadmin bypass', 'key' => 'view_everything',
    'rule_class' => \App\Rules\Access\IsSuperAdminRule::class,
    'ruleable_type' => null,      // global
    'priority' => 1000,
]);
```

(Note the `key` still follows the prefix convention for whichever action you check.)

---

## Configuration reference

`config/access-control.php` (published):

```php
'default_resolution'     => env('ACCESS_CONTROL_RESOLUTION', 'any'),   // any | all | priority
'default_scope_grouping' => env('ACCESS_CONTROL_SCOPE_GROUPING', 'and'), // and | or

'tables' => [
    'access_rules' => 'access_rules',
    'assignments'  => 'access_rule_assignments',
],

'integrations' => [
    'laravel_gates'     => env('ACCESS_CONTROL_GATES', true),   // register Gate::before
    'laravel_policies'  => env('ACCESS_CONTROL_POLICIES', true),// defer to policies when no rules
    'spatie_permission' => env('ACCESS_CONTROL_SPATIE', true),  // include role-assigned rules
],

// Per-model overrides (see next section)
'models' => [
    // \App\Models\Ticket::class => ['resolution_logic' => 'any', 'scope_grouping' => 'and'],
],

// What filterQuery() does when NO rules are assigned:
//  - if a model is listed here, it filters by that column = user id
//  - otherwise it returns NO rows (deny-by-default)
'fallback' => [
    // \App\Models\Ticket::class => ['column' => 'assignee_id'],
],

'standard_actions' => ['view', 'create', 'update', 'delete', 'restore', 'force_delete'],

'cache' => [
    'enabled' => env('ACCESS_CONTROL_CACHE', true),
    'ttl'     => env('ACCESS_CONTROL_CACHE_TTL', 3600), // seconds
    'prefix'  => 'access_control',
],

'logging' => [
    'enabled' => env('ACCESS_CONTROL_LOGGING', false),
    'channel' => env('ACCESS_CONTROL_LOG_CHANNEL', 'stack'),
],
```

---

## Gate & Policy integration

When `integrations.laravel_gates` is on, the package registers a `Gate::before` hook. For any
ability checked against a model that uses `HasAccessRules`:

- Matching rules **grant** ⇒ the gate returns `true`.
- Matching rules **deny** ⇒ the gate returns `false` (**authoritative** — your policy does not run).
- **No** applicable rules ⇒ the gate abstains (`null`), so your normal Policy/Gate runs.

This lets deny rules genuinely block through `@can` / `$user->can()`, while leaving unmanaged
abilities to your existing policies. A model can opt out per-instance via
`shouldIntegrateWithPolicies()` returning `false`.

---

## Spatie Permission (roles)

If `integrations.spatie_permission` is enabled and your user exposes a `roles` relation, rules
assigned to the user's roles are included automatically. Assign rules to a role the same way:

```php
$role = \Spatie\Permission\Models\Role::findByName('manager');
$role->assignAccessRule($rule);   // Role uses CanBeRestricted
```

Role rules are fetched in a **single** query (no N+1 across roles) and merged with the user's own.

---

## Route middleware

The package registers an `acl` route-middleware alias. It runs a per-record `can()` check against
a route-bound model and aborts `403` on denial.

```php
// Explicit param name (recommended):
Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
    ->middleware('acl:view,ticket');

// Or let it pick the first bound model that uses HasAccessRules:
Route::get('/tickets/{ticket}', ...)->middleware('acl:view');
```

- The model must be resolved by **route-model binding** before this middleware runs — keep `acl`
  after `SubstituteBindings` (the default in the `web` group).
- **Index routes** (no bound model) can't be filtered from middleware; use
  `Ticket::accessibleBy($user, 'view')` in the controller instead.

---

## Events

Every resolved decision (via `can()` / `decide()` / the Gate) dispatches one of:

```php
OthmanHaba\LaravelModelAcl\Events\AccessGranted  // ->user, ->action, ->model, ->matchedRules
OthmanHaba\LaravelModelAcl\Events\AccessDenied   // ->user, ->action, ->model, ->evaluatedRules
```

(No event is fired for the "no applicable rules" case, since nothing was evaluated.) Use them for
audit logging:

```php
Event::listen(AccessDenied::class, function ($e) {
    Log::warning("Denied {$e->action} on ".$e->model::class." #{$e->model->getKey()} for user #{$e->user->getAuthIdentifier()}");
});
```

---

## Caching

Rule lookups are cached when `cache.enabled` is true (uses your default cache store). Invalidation
is handled for you:

- Creating/updating/deleting an `AccessRule` flushes the cache.
- `assignAccessRule` / `removeAccessRule` / `syncAccessRules` flush the cache.

Under the hood a **version counter** is embedded in every cache key; flushing just bumps the
counter, so stale entries become unreachable and expire via TTL. This works on **any** cache store
(no tag support required). Force a flush yourself if you write to the tables directly:

```php
OthmanHaba\LaravelModelAcl\Services\AccessControlService::flushCache();
```

---

## Per-model configuration & the `Authorizable` interface

Override behaviour for a specific model via config:

```php
'models' => [
    \App\Models\Ticket::class => [
        'resolution_logic'        => 'any',   // any | all | priority
        'scope_grouping'          => 'and',   // and | or
        'integrate_with_policies' => true,
    ],
    \App\Models\Post::class => [
        'resolution_logic'        => 'priority',
        'scope_grouping'          => 'or',
        'integrate_with_policies' => false,
    ],
],
```

Or take full programmatic control by implementing `Authorizable` on the model (overrides config):

```php
use OthmanHaba\LaravelModelAcl\Contracts\Authorizable;

class Ticket extends Model implements Authorizable
{
    use HasAccessRules;

    public function getAccessResolutionLogic(): string { return 'any'; }
    public function getScopeGroupingStrategy(): string { return 'and'; }
    public function getActionPrefix(): string { return 'ticket'; }
    public function shouldIntegrateWithPolicies(): bool { return true; }
}
```

---

## API reference

### AccessControlService
```php
$acl = app(OthmanHaba\LaravelModelAcl\Services\AccessControlService::class);

$acl->can(Authenticatable $user, string $action, Model $model): bool;
// Tri-state: true = grant, false = deny, null = no applicable rules (used by Gate::before)
$acl->decide(Authenticatable $user, string $action, Model $model): ?bool;
$acl->filterQuery(Authenticatable $user, string $action, ?Builder $query = null, ?string $modelClass = null): Builder;

AccessControlService::flushCache(): void; // static
```

### CanBeRestricted (users / roles)
```php
$user->assignAccessRule($rule);           // AccessRule|int
$user->removeAccessRule($rule);
$user->syncAccessRules(array $ruleIds);
$user->hasAccessRule($rule): bool;
$user->canAccess(string $action, $model): bool;
$user->assignedAccessRules;               // MorphToMany relation
$user->ruleAssignments;                   // MorphMany relation
```

### HasAccessRules (protected models)
```php
$model->accessRules;                                 // MorphMany relation
Ticket::accessibleBy($user, 'view')->get();          // filtered query scope
```

---

## Testing

```bash
composer test
# or
vendor/bin/phpunit
```

The suite uses Orchestra Testbench with an in-memory SQLite database and covers rule resolution,
deny precedence, query filtering, Gate integration, events, middleware, and cache invalidation.

## License

MIT.
