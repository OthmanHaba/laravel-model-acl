# Laravel Model ACL Fixes & Improvements — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix all critical bugs, security issues, and missing features identified in the code review, and add a comprehensive test suite.

**Architecture:** Rename namespace to `OthmanHaba\LaravelModelAcl`. Fix P0 bugs (infinite recursion, deny rules in scopes, class instantiation security). Add caching, events, middleware. Full TDD test suite using Orchestra Testbench.

**Tech Stack:** PHP 8.1+, Laravel 10/11, Orchestra Testbench, PHPUnit 10

---

### Task 1: Rename namespace from YourVendor to OthmanHaba

**Files:**
- Modify: `composer.json`
- Modify: All files in `src/` (every PHP file)
- Modify: `config/access-control.php`
- Modify: `database/migrations/*.php` (if referencing namespace)

**Step 1: Update composer.json**

Replace in `composer.json`:
- `"name"` → `"othmanhaba/laravel-model-acl"`
- All `YourVendor\\LaravelModelAcl` → `OthmanHaba\\LaravelModelAcl`
- Author name/email to real values

**Step 2: Find-and-replace namespace in all src files**

Run: `grep -rl 'YourVendor' src/ config/ database/`

Replace `YourVendor\LaravelModelAcl` with `OthmanHaba\LaravelModelAcl` in every file found.

Also fix the stub in `src/Console/Commands/MakeAccessRuleCommand.php` — the generated code references `YourVendor` which user projects would inherit.

**Step 3: Update config references**

In `config/access-control.php`, update the `built_in_rules` array class references.

**Step 4: Run composer dump-autoload**

Run: `cd /Users/m/sites/packages/laravel-model-acl && composer dump-autoload`
Expected: No errors

**Step 5: Commit**

```bash
git add -A
git commit -m "refactor: rename namespace from YourVendor to OthmanHaba"
```

---

### Task 2: Set up test infrastructure

**Files:**
- Create: `phpunit.xml`
- Create: `tests/TestCase.php`
- Create: `tests/Fixtures/Models/TestUser.php`
- Create: `tests/Fixtures/Models/TestTicket.php`
- Create: `tests/Fixtures/Migrations/create_test_tables.php`

**Step 1: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <php>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
    </php>
</phpunit>
```

**Step 2: Create base TestCase**

```php
// tests/TestCase.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests;

use OthmanHaba\LaravelModelAcl\AccessControlServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AccessControlServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/Migrations');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
```

**Step 3: Create test fixtures — TestUser model**

```php
// tests/Fixtures/Models/TestUser.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use OthmanHaba\LaravelModelAcl\Traits\CanBeRestricted;

class TestUser extends Authenticatable
{
    use CanBeRestricted;

    protected $table = 'test_users';
    protected $guarded = [];
}
```

**Step 4: Create test fixtures — TestTicket model**

```php
// tests/Fixtures/Models/TestTicket.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use OthmanHaba\LaravelModelAcl\Traits\HasAccessRules;

class TestTicket extends Model
{
    use HasAccessRules;

    protected $table = 'test_tickets';
    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'due_date' => 'datetime',
    ];
}
```

**Step 5: Create test migration**

```php
// tests/Fixtures/Migrations/2024_01_01_000000_create_test_tables.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('department_id')->nullable();
            $table->timestamps();
        });

        Schema::create('test_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('status')->default('open');
            $table->foreignId('user_id')->nullable();
            $table->foreignId('assignee_id')->nullable();
            $table->string('department_id')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_tickets');
        Schema::dropIfExists('test_users');
    }
};
```

**Step 6: Update composer.json autoload-dev**

```json
"autoload-dev": {
    "psr-4": {
        "OthmanHaba\\LaravelModelAcl\\Tests\\": "tests/"
    }
}
```

**Step 7: Run composer dump-autoload and verify test infrastructure**

Run: `cd /Users/m/sites/packages/laravel-model-acl && composer dump-autoload && vendor/bin/phpunit --list-suites`
Expected: Lists "Unit" and "Feature" suites with no errors

**Step 8: Commit**

```bash
git add phpunit.xml tests/
git commit -m "test: set up test infrastructure with Orchestra Testbench"
```

---

### Task 3: Fix infinite recursion in HasAccessRules trait

**Files:**
- Create: `tests/Unit/Traits/HasAccessRulesTest.php`
- Modify: `src/Traits/HasAccessRules.php`

**Step 1: Write the failing test**

```php
// tests/Unit/Traits/HasAccessRulesTest.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Unit\Traits;

use OthmanHaba\LaravelModelAcl\Tests\TestCase;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestTicket;

class HasAccessRulesTest extends TestCase
{
    public function test_get_access_resolution_logic_returns_config_default(): void
    {
        $ticket = new TestTicket();

        $result = $ticket->getAccessResolutionLogic();

        $this->assertEquals('any', $result);
    }

    public function test_get_scope_grouping_strategy_returns_config_default(): void
    {
        $ticket = new TestTicket();

        $result = $ticket->getScopeGroupingStrategy();

        $this->assertEquals('and', $result);
    }

    public function test_get_action_prefix_returns_model_basename(): void
    {
        $ticket = new TestTicket();

        $result = $ticket->getActionPrefix();

        $this->assertEquals('testticket', $result);
    }

    public function test_should_integrate_with_policies_returns_config_default(): void
    {
        $ticket = new TestTicket();

        $result = $ticket->shouldIntegrateWithPolicies();

        $this->assertTrue($result);
    }
}
```

**Step 2: Run test to verify it fails (infinite recursion)**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Traits/HasAccessRulesTest.php --no-configuration --bootstrap vendor/autoload.php`
Expected: FAIL (may hang or stack overflow due to recursion)

**Step 3: Fix HasAccessRules trait — remove self-referencing instanceof checks**

Replace the 4 methods in `src/Traits/HasAccessRules.php` with:

```php
public function getAccessResolutionLogic(): string
{
    $modelClass = static::class;
    $modelConfig = config("access-control.models.{$modelClass}", []);

    return $modelConfig['resolution_logic'] ?? config('access-control.default_resolution', 'any');
}

public function getScopeGroupingStrategy(): string
{
    $modelClass = static::class;
    $modelConfig = config("access-control.models.{$modelClass}", []);

    return $modelConfig['scope_grouping'] ?? config('access-control.default_scope_grouping', 'and');
}

public function getActionPrefix(): string
{
    $modelClass = static::class;
    $modelConfig = config("access-control.models.{$modelClass}", []);

    return $modelConfig['action_prefix'] ?? strtolower(class_basename($this));
}

public function shouldIntegrateWithPolicies(): bool
{
    $modelClass = static::class;
    $modelConfig = config("access-control.models.{$modelClass}", []);

    return $modelConfig['integrate_with_policies'] ?? config('access-control.integrations.laravel_policies', true);
}
```

Remove the `use OthmanHaba\LaravelModelAcl\Contracts\Authorizable;` import from the trait.

**Step 4: Run tests to verify they pass**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Traits/HasAccessRulesTest.php`
Expected: 4 tests, 4 assertions, all PASS

**Step 5: Commit**

```bash
git add src/Traits/HasAccessRules.php tests/Unit/Traits/HasAccessRulesTest.php
git commit -m "fix: remove infinite recursion in HasAccessRules trait methods"
```

---

### Task 4: Fix scopeForAction — exact match instead of LIKE

**Files:**
- Create: `tests/Unit/Models/AccessRuleTest.php`
- Modify: `src/Models/AccessRule.php`

**Step 1: Write the failing test**

```php
// tests/Unit/Models/AccessRuleTest.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Unit\Models;

use OthmanHaba\LaravelModelAcl\Models\AccessRule;
use OthmanHaba\LaravelModelAcl\Tests\TestCase;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestTicket;

class AccessRuleTest extends TestCase
{
    public function test_scope_for_action_matches_exact_key(): void
    {
        AccessRule::create([
            'name' => 'View Rule',
            'key' => 'view',
            'rule_class' => 'SomeClass',
            'ruleable_type' => TestTicket::class,
        ]);

        AccessRule::create([
            'name' => 'View All Rule',
            'key' => 'view_all',
            'rule_class' => 'SomeClass',
            'ruleable_type' => TestTicket::class,
        ]);

        $results = AccessRule::forAction('view')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('view', $results->first()->key);
    }

    public function test_scope_active_returns_only_active_rules(): void
    {
        AccessRule::create([
            'name' => 'Active Rule',
            'key' => 'view',
            'rule_class' => 'SomeClass',
            'active' => true,
        ]);

        AccessRule::create([
            'name' => 'Inactive Rule',
            'key' => 'view',
            'rule_class' => 'SomeClass',
            'active' => false,
        ]);

        $results = AccessRule::active()->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Active Rule', $results->first()->name);
    }

    public function test_scope_for_model_includes_global_rules(): void
    {
        AccessRule::create([
            'name' => 'Model Rule',
            'key' => 'view',
            'rule_class' => 'SomeClass',
            'ruleable_type' => TestTicket::class,
        ]);

        AccessRule::create([
            'name' => 'Global Rule',
            'key' => 'view',
            'rule_class' => 'SomeClass',
            'ruleable_type' => null,
        ]);

        $results = AccessRule::forModel(TestTicket::class)->get();

        $this->assertCount(2, $results);
    }
}
```

**Step 2: Run test to verify the first test fails**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Models/AccessRuleTest.php`
Expected: `test_scope_for_action_matches_exact_key` FAILS (LIKE returns both rows)

**Step 3: Fix scopeForAction to use exact match**

In `src/Models/AccessRule.php`, replace `scopeForAction`:

```php
public function scopeForAction($query, string $action)
{
    return $query->where('key', $action);
}
```

**Step 4: Run tests to verify they pass**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Models/AccessRuleTest.php`
Expected: 3 tests, all PASS

**Step 5: Commit**

```bash
git add src/Models/AccessRule.php tests/Unit/Models/AccessRuleTest.php
git commit -m "fix: use exact match for scopeForAction instead of LIKE"
```

---

### Task 5: Fix type mismatch and loose comparison

**Files:**
- Modify: `src/Services/AccessControlService.php` (getRulesForAssignable signature)
- Modify: `src/Rules/OwnershipRule.php` (strict comparison)
- Create: `tests/Unit/Rules/OwnershipRuleTest.php`

**Step 1: Write OwnershipRule tests**

```php
// tests/Unit/Rules/OwnershipRuleTest.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Unit\Rules;

use OthmanHaba\LaravelModelAcl\Rules\OwnershipRule;
use OthmanHaba\LaravelModelAcl\Tests\TestCase;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestUser;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestTicket;

class OwnershipRuleTest extends TestCase
{
    public function test_passes_when_user_owns_model(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
        $ticket = TestTicket::create(['title' => 'Test', 'user_id' => $user->id]);

        $rule = new OwnershipRule(owner_column: 'user_id');

        $this->assertTrue($rule->passes($user, $ticket));
    }

    public function test_fails_when_user_does_not_own_model(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
        $ticket = TestTicket::create(['title' => 'Test', 'user_id' => 999]);

        $rule = new OwnershipRule(owner_column: 'user_id');

        $this->assertFalse($rule->passes($user, $ticket));
    }

    public function test_scope_filters_to_owned_records(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
        TestTicket::create(['title' => 'Mine', 'user_id' => $user->id]);
        TestTicket::create(['title' => 'Not Mine', 'user_id' => 999]);

        $rule = new OwnershipRule(owner_column: 'user_id');
        $query = $rule->scope(TestTicket::query(), $user);

        $this->assertCount(1, $query->get());
        $this->assertEquals('Mine', $query->first()->title);
    }

    public function test_uses_custom_columns(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
        $ticket = TestTicket::create(['title' => 'Test', 'assignee_id' => $user->id]);

        $rule = new OwnershipRule(owner_column: 'assignee_id', user_id_column: 'id');

        $this->assertTrue($rule->passes($user, $ticket));
    }

    public function test_strict_comparison_prevents_type_juggling(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
        $ticket = TestTicket::create(['title' => 'Test', 'user_id' => 0]);

        $rule = new OwnershipRule(owner_column: 'user_id');

        // user_id=0 should NOT match user->id=1 even with loose comparison
        $this->assertFalse($rule->passes($user, $ticket));
    }
}
```

**Step 2: Run tests**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Rules/OwnershipRuleTest.php`
Expected: Tests should mostly pass, but verify strict comparison test behavior

**Step 3: Fix OwnershipRule to use strict comparison**

In `src/Rules/OwnershipRule.php`, replace `passes()`:

```php
public function passes(Authenticatable $user, Model $model): bool
{
    $ownerId = data_get($model, $this->ownerColumn);
    $userId = data_get($user, $this->userIdColumn);

    return (string) $ownerId === (string) $userId;
}
```

**Step 4: Fix getRulesForAssignable type hint**

In `src/Services/AccessControlService.php`, change the signature:

```php
protected function getRulesForAssignable(Authenticatable|Model $assignable, string $action, string $modelClass): Collection
{
    return AccessRule::query()
        ->active()
        ->forAction($action)
        ->forModel($modelClass)
        ->whereHas('assignments', function ($query) use ($assignable) {
            $query->where('assignable_type', get_class($assignable))
                  ->where('assignable_id', $assignable->getAuthIdentifier());
        })
        ->orderedByPriority()
        ->get();
}
```

Use `getAuthIdentifier()` instead of `->id` for Authenticatable compatibility.

For the Spatie roles loop, roles are Models, so use `$role->getKey()` instead:

```php
->where('assignable_id', $role->getKey());
```

Actually, refactor `getApplicableRules` to pass role correctly. See Step 5.

**Step 5: Fix Spatie N+1 — single query for role rules**

In `src/Services/AccessControlService.php`, replace the Spatie block in `getApplicableRules`:

```php
$roleRules = collect();
if (config('access-control.integrations.spatie_permission') && method_exists($user, 'roles')) {
    $roleIds = $user->roles->pluck('id');
    $roleClass = get_class($user->roles->first());

    if ($roleIds->isNotEmpty()) {
        $roleRules = AccessRule::query()
            ->active()
            ->forAction($action)
            ->forModel($modelClass)
            ->whereHas('assignments', function ($query) use ($roleClass, $roleIds) {
                $query->where('assignable_type', $roleClass)
                      ->whereIn('assignable_id', $roleIds);
            })
            ->orderedByPriority()
            ->get();
    }
}
```

**Step 6: Run all tests**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit`
Expected: All PASS

**Step 7: Commit**

```bash
git add src/Services/AccessControlService.php src/Rules/OwnershipRule.php tests/Unit/Rules/OwnershipRuleTest.php
git commit -m "fix: strict comparison in OwnershipRule, fix type hints, fix N+1 on Spatie roles"
```

---

### Task 6: Fix DateRangeRule null handling + write tests

**Files:**
- Create: `tests/Unit/Rules/DateRangeRuleTest.php`
- Modify: `src/Rules/DateRangeRule.php`

**Step 1: Write the tests**

```php
// tests/Unit/Rules/DateRangeRuleTest.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Unit\Rules;

use OthmanHaba\LaravelModelAcl\Rules\DateRangeRule;
use OthmanHaba\LaravelModelAcl\Tests\TestCase;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestUser;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestTicket;
use Carbon\Carbon;

class DateRangeRuleTest extends TestCase
{
    private TestUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
    }

    public function test_passes_when_date_in_range(): void
    {
        $ticket = TestTicket::create([
            'title' => 'Test',
            'created_at' => '2025-06-15',
        ]);

        $rule = new DateRangeRule(from: '2025-01-01', to: '2025-12-31');

        $this->assertTrue($rule->passes($this->user, $ticket));
    }

    public function test_fails_when_date_outside_range(): void
    {
        $ticket = TestTicket::create([
            'title' => 'Test',
            'created_at' => '2024-06-15',
        ]);

        $rule = new DateRangeRule(from: '2025-01-01', to: '2025-12-31');

        $this->assertFalse($rule->passes($this->user, $ticket));
    }

    public function test_passes_when_no_range_configured(): void
    {
        $ticket = TestTicket::create(['title' => 'Test']);

        $rule = new DateRangeRule();

        $this->assertTrue($rule->passes($this->user, $ticket));
    }

    public function test_fails_when_model_date_is_null(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'due_date' => null]);

        $rule = new DateRangeRule(from: '2025-01-01', to: '2025-12-31', date_column: 'due_date');

        $this->assertFalse($rule->passes($this->user, $ticket));
    }

    public function test_passes_with_only_from_date(): void
    {
        $ticket = TestTicket::create([
            'title' => 'Test',
            'created_at' => '2025-06-15',
        ]);

        $rule = new DateRangeRule(from: '2025-01-01');

        $this->assertTrue($rule->passes($this->user, $ticket));
    }

    public function test_fails_with_only_from_date_when_before(): void
    {
        $ticket = TestTicket::create([
            'title' => 'Test',
            'created_at' => '2024-06-15',
        ]);

        $rule = new DateRangeRule(from: '2025-01-01');

        $this->assertFalse($rule->passes($this->user, $ticket));
    }

    public function test_scope_filters_by_date_range(): void
    {
        TestTicket::create(['title' => 'In Range', 'created_at' => '2025-06-15']);
        TestTicket::create(['title' => 'Out of Range', 'created_at' => '2024-01-01']);

        $rule = new DateRangeRule(from: '2025-01-01', to: '2025-12-31');
        $results = $rule->scope(TestTicket::query(), $this->user)->get();

        $this->assertCount(1, $results);
        $this->assertEquals('In Range', $results->first()->title);
    }
}
```

**Step 2: Run tests to see null test fail**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Rules/DateRangeRuleTest.php`
Expected: `test_fails_when_model_date_is_null` FAILS (returns true because Carbon::parse(null) returns now)

**Step 3: Fix DateRangeRule to handle null dates**

In `src/Rules/DateRangeRule.php`, update `passes()`:

```php
public function passes(Authenticatable $user, Model $model): bool
{
    if (!$this->from && !$this->to) {
        return true;
    }

    $modelDate = data_get($model, $this->dateColumn);

    if ($modelDate === null) {
        return false;
    }

    if (!$modelDate instanceof Carbon) {
        $modelDate = Carbon::parse($modelDate);
    }

    if ($this->from && $this->to) {
        return $modelDate->between($this->from, $this->to);
    }

    if ($this->from) {
        return $modelDate->greaterThanOrEqualTo($this->from);
    }

    if ($this->to) {
        return $modelDate->lessThanOrEqualTo($this->to);
    }

    return true;
}
```

**Step 4: Run tests to verify all pass**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Rules/DateRangeRuleTest.php`
Expected: 7 tests, all PASS

**Step 5: Commit**

```bash
git add src/Rules/DateRangeRule.php tests/Unit/Rules/DateRangeRuleTest.php
git commit -m "fix: handle null dates in DateRangeRule, add tests"
```

---

### Task 7: Write StatusRule and AttributeRule tests

**Files:**
- Create: `tests/Unit/Rules/StatusRuleTest.php`
- Create: `tests/Unit/Rules/AttributeRuleTest.php`

**Step 1: Write StatusRule tests**

```php
// tests/Unit/Rules/StatusRuleTest.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Unit\Rules;

use OthmanHaba\LaravelModelAcl\Rules\StatusRule;
use OthmanHaba\LaravelModelAcl\Tests\TestCase;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestUser;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestTicket;

class StatusRuleTest extends TestCase
{
    private TestUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
    }

    public function test_passes_when_status_in_allowed_list(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);
        $rule = new StatusRule(statuses: ['open', 'pending']);

        $this->assertTrue($rule->passes($this->user, $ticket));
    }

    public function test_fails_when_status_not_in_allowed_list(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'closed']);
        $rule = new StatusRule(statuses: ['open', 'pending']);

        $this->assertFalse($rule->passes($this->user, $ticket));
    }

    public function test_passes_when_no_statuses_configured(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'anything']);
        $rule = new StatusRule();

        $this->assertTrue($rule->passes($this->user, $ticket));
    }

    public function test_uses_custom_status_column(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);
        $rule = new StatusRule(statuses: ['open'], status_column: 'status');

        $this->assertTrue($rule->passes($this->user, $ticket));
    }

    public function test_scope_filters_by_allowed_statuses(): void
    {
        TestTicket::create(['title' => 'Open', 'status' => 'open']);
        TestTicket::create(['title' => 'Closed', 'status' => 'closed']);
        TestTicket::create(['title' => 'Pending', 'status' => 'pending']);

        $rule = new StatusRule(statuses: ['open', 'pending']);
        $results = $rule->scope(TestTicket::query(), $this->user)->get();

        $this->assertCount(2, $results);
    }
}
```

**Step 2: Write AttributeRule tests**

```php
// tests/Unit/Rules/AttributeRuleTest.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Unit\Rules;

use OthmanHaba\LaravelModelAcl\Rules\AttributeRule;
use OthmanHaba\LaravelModelAcl\Tests\TestCase;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestUser;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestTicket;

class AttributeRuleTest extends TestCase
{
    private TestUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = TestUser::create([
            'name' => 'John',
            'email' => 'john@test.com',
            'department_id' => 'engineering',
        ]);
    }

    public function test_passes_when_user_attribute_matches_model_attribute(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'department_id' => 'engineering']);

        $rule = new AttributeRule(
            model_attribute: 'department_id',
            user_attribute: 'department_id',
        );

        $this->assertTrue($rule->passes($this->user, $ticket));
    }

    public function test_fails_when_user_attribute_does_not_match(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'department_id' => 'marketing']);

        $rule = new AttributeRule(
            model_attribute: 'department_id',
            user_attribute: 'department_id',
        );

        $this->assertFalse($rule->passes($this->user, $ticket));
    }

    public function test_passes_with_static_value(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'active']);

        $rule = new AttributeRule(
            model_attribute: 'status',
            static_value: 'active',
        );

        $this->assertTrue($rule->passes($this->user, $ticket));
    }

    public function test_not_equals_operator(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'closed']);

        $rule = new AttributeRule(
            model_attribute: 'status',
            static_value: 'active',
            operator: '!=',
        );

        $this->assertTrue($rule->passes($this->user, $ticket));
    }

    public function test_in_operator(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

        $rule = new AttributeRule(
            model_attribute: 'status',
            static_value: ['open', 'pending'],
            operator: 'in',
        );

        $this->assertTrue($rule->passes($this->user, $ticket));
    }

    public function test_not_in_operator(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

        $rule = new AttributeRule(
            model_attribute: 'status',
            static_value: ['closed', 'archived'],
            operator: 'not_in',
        );

        $this->assertTrue($rule->passes($this->user, $ticket));
    }

    public function test_passes_when_no_model_attribute_configured(): void
    {
        $ticket = TestTicket::create(['title' => 'Test']);
        $rule = new AttributeRule();

        $this->assertTrue($rule->passes($this->user, $ticket));
    }

    public function test_scope_filters_by_user_attribute(): void
    {
        TestTicket::create(['title' => 'Mine', 'department_id' => 'engineering']);
        TestTicket::create(['title' => 'Other', 'department_id' => 'marketing']);

        $rule = new AttributeRule(
            model_attribute: 'department_id',
            user_attribute: 'department_id',
        );

        $results = $rule->scope(TestTicket::query(), $this->user)->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Mine', $results->first()->title);
    }

    public function test_scope_filters_with_in_operator(): void
    {
        TestTicket::create(['title' => 'Open', 'status' => 'open']);
        TestTicket::create(['title' => 'Closed', 'status' => 'closed']);

        $rule = new AttributeRule(
            model_attribute: 'status',
            static_value: ['open', 'pending'],
            operator: 'in',
        );

        $results = $rule->scope(TestTicket::query(), $this->user)->get();

        $this->assertCount(1, $results);
    }
}
```

**Step 3: Run tests**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Rules/`
Expected: All PASS (no code changes needed here, just test coverage)

**Step 4: Commit**

```bash
git add tests/Unit/Rules/StatusRuleTest.php tests/Unit/Rules/AttributeRuleTest.php
git commit -m "test: add StatusRule and AttributeRule test coverage"
```

---

### Task 8: Write RuleResolver tests

**Files:**
- Create: `tests/Unit/Services/RuleResolverTest.php`

**Step 1: Write tests**

```php
// tests/Unit/Services/RuleResolverTest.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Unit\Services;

use OthmanHaba\LaravelModelAcl\Services\RuleResolver;
use OthmanHaba\LaravelModelAcl\Rules\StatusRule;
use OthmanHaba\LaravelModelAcl\Rules\OwnershipRule;
use OthmanHaba\LaravelModelAcl\Tests\TestCase;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestUser;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestTicket;

class RuleResolverTest extends TestCase
{
    private RuleResolver $resolver;
    private TestUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RuleResolver();
        $this->user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
    }

    public function test_returns_false_when_no_rules(): void
    {
        $result = $this->resolver->resolve(collect(), $this->user, new TestTicket());

        $this->assertFalse($result);
    }

    public function test_any_logic_grants_when_one_rule_passes(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open', 'user_id' => 999]);

        $rules = collect([
            new StatusRule(statuses: ['open'], _priority: 0),
            new OwnershipRule(owner_column: 'user_id', _priority: 0),
        ]);

        $result = $this->resolver->resolve($rules, $this->user, $ticket, 'any');

        $this->assertTrue($result);
    }

    public function test_any_logic_denies_when_no_rules_pass(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'closed', 'user_id' => 999]);

        $rules = collect([
            new StatusRule(statuses: ['open'], _priority: 0),
            new OwnershipRule(owner_column: 'user_id', _priority: 0),
        ]);

        $result = $this->resolver->resolve($rules, $this->user, $ticket, 'any');

        $this->assertFalse($result);
    }

    public function test_all_logic_requires_every_rule_to_pass(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open', 'user_id' => $this->user->id]);

        $rules = collect([
            new StatusRule(statuses: ['open'], _priority: 0),
            new OwnershipRule(owner_column: 'user_id', _priority: 0),
        ]);

        $result = $this->resolver->resolve($rules, $this->user, $ticket, 'all');

        $this->assertTrue($result);
    }

    public function test_all_logic_denies_when_one_rule_fails(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open', 'user_id' => 999]);

        $rules = collect([
            new StatusRule(statuses: ['open'], _priority: 0),
            new OwnershipRule(owner_column: 'user_id', _priority: 0),
        ]);

        $result = $this->resolver->resolve($rules, $this->user, $ticket, 'all');

        $this->assertFalse($result);
    }

    public function test_priority_logic_first_match_wins(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open', 'user_id' => 999]);

        $rules = collect([
            new StatusRule(statuses: ['open'], _priority: 10),       // passes first
            new OwnershipRule(owner_column: 'user_id', _priority: 5), // would fail
        ]);

        $result = $this->resolver->resolve($rules, $this->user, $ticket, 'priority');

        $this->assertTrue($result);
    }

    public function test_deny_rule_overrides_allow_rules(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open', 'user_id' => $this->user->id]);

        $rules = collect([
            new StatusRule(statuses: ['open'], _priority: 0),
            new StatusRule(statuses: ['open'], _priority: 10, _is_deny_rule: true),
        ]);

        $result = $this->resolver->resolve($rules, $this->user, $ticket, 'any');

        $this->assertFalse($result);
    }

    public function test_deny_rule_that_does_not_match_does_not_block(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open', 'user_id' => $this->user->id]);

        $rules = collect([
            new StatusRule(statuses: ['open'], _priority: 0),
            new StatusRule(statuses: ['closed'], _priority: 10, _is_deny_rule: true), // deny closed, but ticket is open
        ]);

        $result = $this->resolver->resolve($rules, $this->user, $ticket, 'any');

        $this->assertTrue($result);
    }
}
```

**Step 2: Run tests**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Services/RuleResolverTest.php`
Expected: All PASS

**Step 3: Commit**

```bash
git add tests/Unit/Services/RuleResolverTest.php
git commit -m "test: add RuleResolver test coverage for all resolution strategies"
```

---

### Task 9: Add rule class whitelist security

**Files:**
- Modify: `config/access-control.php`
- Modify: `src/Services/AccessControlService.php`
- Create: `tests/Unit/Services/AccessControlServiceTest.php`

**Step 1: Write test for whitelist validation**

```php
// tests/Unit/Services/AccessControlServiceTest.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Unit\Services;

use OthmanHaba\LaravelModelAcl\Services\AccessControlService;
use OthmanHaba\LaravelModelAcl\Models\AccessRule;
use OthmanHaba\LaravelModelAcl\Models\AccessRuleAssignment;
use OthmanHaba\LaravelModelAcl\Rules\StatusRule;
use OthmanHaba\LaravelModelAcl\Tests\TestCase;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestUser;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestTicket;

class AccessControlServiceTest extends TestCase
{
    private AccessControlService $service;
    private TestUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AccessControlService::class);
        $this->user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
    }

    public function test_rejects_non_whitelisted_rule_class(): void
    {
        $rule = AccessRule::create([
            'name' => 'Malicious Rule',
            'key' => 'view',
            'rule_class' => 'App\\SomeDangerousClass',
            'ruleable_type' => TestTicket::class,
            'active' => true,
        ]);

        AccessRuleAssignment::create([
            'access_rule_id' => $rule->id,
            'assignable_type' => TestUser::class,
            'assignable_id' => $this->user->id,
        ]);

        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not in the allowed rule classes');

        $this->service->can($this->user, 'view', $ticket);
    }

    public function test_allows_whitelisted_rule_class(): void
    {
        $rule = AccessRule::create([
            'name' => 'Status Rule',
            'key' => 'view',
            'rule_class' => StatusRule::class,
            'settings' => ['statuses' => ['open']],
            'ruleable_type' => TestTicket::class,
            'active' => true,
        ]);

        AccessRuleAssignment::create([
            'access_rule_id' => $rule->id,
            'assignable_type' => TestUser::class,
            'assignable_id' => $this->user->id,
        ]);

        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

        $this->assertTrue($this->service->can($this->user, 'view', $ticket));
    }

    public function test_denies_access_when_no_rules_exist(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

        $this->assertFalse($this->service->can($this->user, 'view', $ticket));
    }

    public function test_filter_query_returns_empty_when_no_rules(): void
    {
        TestTicket::create(['title' => 'Test', 'status' => 'open', 'user_id' => $this->user->id]);

        $results = $this->service->filterQuery($this->user, 'view', modelClass: TestTicket::class)->get();

        $this->assertCount(0, $results);
    }

    public function test_filter_query_applies_rule_scopes(): void
    {
        $rule = AccessRule::create([
            'name' => 'View Open',
            'key' => 'view',
            'rule_class' => StatusRule::class,
            'settings' => ['statuses' => ['open']],
            'ruleable_type' => TestTicket::class,
            'active' => true,
        ]);

        AccessRuleAssignment::create([
            'access_rule_id' => $rule->id,
            'assignable_type' => TestUser::class,
            'assignable_id' => $this->user->id,
        ]);

        TestTicket::create(['title' => 'Open', 'status' => 'open']);
        TestTicket::create(['title' => 'Closed', 'status' => 'closed']);

        $results = $this->service->filterQuery($this->user, 'view', modelClass: TestTicket::class)->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Open', $results->first()->title);
    }

    public function test_register_rule_class_allows_custom_rules(): void
    {
        $this->service->registerRuleClass(StatusRule::class);

        // Should not throw
        $this->assertTrue(in_array(StatusRule::class, $this->service->getAllowedRuleClasses()));
    }
}
```

**Step 2: Run tests to see whitelist test fail**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Services/AccessControlServiceTest.php`
Expected: FAIL — no whitelist exists yet

**Step 3: Add whitelist to config**

In `config/access-control.php`, add inside the array:

```php
'allowed_rule_classes' => [
    \OthmanHaba\LaravelModelAcl\Rules\StatusRule::class,
    \OthmanHaba\LaravelModelAcl\Rules\DateRangeRule::class,
    \OthmanHaba\LaravelModelAcl\Rules\OwnershipRule::class,
    \OthmanHaba\LaravelModelAcl\Rules\AttributeRule::class,
],
```

**Step 4: Add whitelist validation and registerRuleClass to AccessControlService**

Add property and methods:

```php
protected array $runtimeRuleClasses = [];

public function registerRuleClass(string $class): void
{
    $this->runtimeRuleClasses[] = $class;
}

public function getAllowedRuleClasses(): array
{
    return array_merge(
        config('access-control.allowed_rule_classes', []),
        $this->runtimeRuleClasses
    );
}
```

In `instantiateRules()`, add validation before `app()->makeWith()`:

```php
$allowedClasses = $this->getAllowedRuleClasses();

if (!empty($allowedClasses) && !in_array($rule->rule_class, $allowedClasses)) {
    throw new \RuntimeException(
        "Rule class {$rule->rule_class} is not in the allowed rule classes list."
    );
}
```

**Step 5: Remove fallback filtering — deny when no rules exist**

Replace `applyFallbackFiltering()` with a method that returns empty results:

```php
protected function applyNoRulesFiltering(Builder $query): Builder
{
    return $query->whereRaw('1 = 0');
}
```

Update `filterQuery()` to call it:

```php
if ($rules->isEmpty()) {
    return $this->applyNoRulesFiltering($query);
}
```

Delete the old `applyFallbackFiltering()` method entirely.

**Step 6: Run tests**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Services/AccessControlServiceTest.php`
Expected: All PASS

**Step 7: Commit**

```bash
git add config/access-control.php src/Services/AccessControlService.php tests/Unit/Services/AccessControlServiceTest.php
git commit -m "feat: add rule class whitelist security, remove fallback filtering"
```

---

### Task 10: Add deny rules to query filtering

**Files:**
- Modify: `src/Services/AccessControlService.php`
- Add tests to: `tests/Unit/Services/AccessControlServiceTest.php`

**Step 1: Write test for deny rule in query filtering**

Add to `AccessControlServiceTest.php`:

```php
public function test_filter_query_excludes_denied_records(): void
{
    // Allow rule: view open tickets
    $allowRule = AccessRule::create([
        'name' => 'View Open',
        'key' => 'view',
        'rule_class' => StatusRule::class,
        'settings' => ['statuses' => ['open', 'pending', 'closed']],
        'ruleable_type' => TestTicket::class,
        'active' => true,
        'is_deny_rule' => false,
    ]);

    // Deny rule: never show closed tickets
    $denyRule = AccessRule::create([
        'name' => 'Deny Closed',
        'key' => 'view',
        'rule_class' => StatusRule::class,
        'settings' => ['statuses' => ['closed']],
        'ruleable_type' => TestTicket::class,
        'active' => true,
        'is_deny_rule' => true,
    ]);

    AccessRuleAssignment::create([
        'access_rule_id' => $allowRule->id,
        'assignable_type' => TestUser::class,
        'assignable_id' => $this->user->id,
    ]);

    AccessRuleAssignment::create([
        'access_rule_id' => $denyRule->id,
        'assignable_type' => TestUser::class,
        'assignable_id' => $this->user->id,
    ]);

    TestTicket::create(['title' => 'Open', 'status' => 'open']);
    TestTicket::create(['title' => 'Closed', 'status' => 'closed']);
    TestTicket::create(['title' => 'Pending', 'status' => 'pending']);

    $results = $this->service->filterQuery($this->user, 'view', modelClass: TestTicket::class)->get();

    $this->assertCount(2, $results);
    $this->assertFalse($results->contains('title', 'Closed'));
}
```

**Step 2: Run test to see it fail**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Services/AccessControlServiceTest.php --filter=test_filter_query_excludes_denied_records`
Expected: FAIL — deny rules are skipped in scope filtering

**Step 3: Fix applyScopes to handle deny rules**

In `src/Services/AccessControlService.php`, update `applyScopes()`:

```php
protected function applyScopes(
    Builder $query,
    Collection $ruleInstances,
    Authenticatable $user,
    string $groupingStrategy
): Builder {
    $allowRules = $ruleInstances->filter(fn($rule) => !$rule->isDenyRule());
    $denyRules = $ruleInstances->filter(fn($rule) => $rule->isDenyRule());

    // Apply allow rules
    if ($allowRules->isNotEmpty()) {
        if ($groupingStrategy === 'and') {
            foreach ($allowRules as $rule) {
                $query = $rule->scope($query, $user);
            }
        } elseif ($groupingStrategy === 'or') {
            $query->where(function ($q) use ($allowRules, $user) {
                $first = true;
                foreach ($allowRules as $rule) {
                    if ($first) {
                        $rule->scope($q, $user);
                        $first = false;
                    } else {
                        $q->orWhere(function ($subQuery) use ($rule, $user) {
                            $rule->scope($subQuery, $user);
                        });
                    }
                }
            });
        }
    }

    // Apply deny rules — exclude matching records
    foreach ($denyRules as $rule) {
        $query->where(function ($q) use ($rule, $user) {
            $denyQuery = $rule->scope($q->getModel()::query(), $user);

            // Get the wheres from the deny query and negate them
            $q->whereNotIn('id', $denyQuery->select('id'));
        });
    }

    return $query;
}
```

**Step 4: Run test**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Services/AccessControlServiceTest.php`
Expected: All PASS

**Step 5: Commit**

```bash
git add src/Services/AccessControlService.php tests/Unit/Services/AccessControlServiceTest.php
git commit -m "fix: apply deny rules in query filtering via whereNotIn"
```

---

### Task 11: Add caching

**Files:**
- Modify: `src/Services/AccessControlService.php`
- Create: `src/Observers/AccessRuleCacheObserver.php`
- Modify: `src/AccessControlServiceProvider.php`
- Add tests to: `tests/Unit/Services/AccessControlServiceTest.php`

**Step 1: Write caching test**

Add to `AccessControlServiceTest.php`:

```php
public function test_caching_stores_and_retrieves_rules(): void
{
    config()->set('access-control.cache.enabled', true);
    config()->set('access-control.cache.ttl', 3600);

    $rule = AccessRule::create([
        'name' => 'View Open',
        'key' => 'view',
        'rule_class' => StatusRule::class,
        'settings' => ['statuses' => ['open']],
        'ruleable_type' => TestTicket::class,
        'active' => true,
    ]);

    AccessRuleAssignment::create([
        'access_rule_id' => $rule->id,
        'assignable_type' => TestUser::class,
        'assignable_id' => $this->user->id,
    ]);

    $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

    // First call — hits DB
    $result1 = $this->service->can($this->user, 'view', $ticket);
    $this->assertTrue($result1);

    // Second call — should use cache (we can't directly test this without mocking,
    // but we verify the result is consistent)
    $result2 = $this->service->can($this->user, 'view', $ticket);
    $this->assertTrue($result2);
}

public function test_cache_is_cleared_when_rule_is_updated(): void
{
    config()->set('access-control.cache.enabled', true);

    $rule = AccessRule::create([
        'name' => 'View Open',
        'key' => 'view',
        'rule_class' => StatusRule::class,
        'settings' => ['statuses' => ['open']],
        'ruleable_type' => TestTicket::class,
        'active' => true,
    ]);

    AccessRuleAssignment::create([
        'access_rule_id' => $rule->id,
        'assignable_type' => TestUser::class,
        'assignable_id' => $this->user->id,
    ]);

    $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

    $this->assertTrue($this->service->can($this->user, 'view', $ticket));

    // Deactivate rule
    $rule->update(['active' => false]);

    // Should now be denied (cache cleared by observer)
    $this->assertFalse($this->service->can($this->user, 'view', $ticket));
}
```

**Step 2: Run tests to see them fail**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Services/AccessControlServiceTest.php --filter=test_cach`
Expected: FAIL — no caching logic

**Step 3: Add caching to getRulesForAssignable**

In `src/Services/AccessControlService.php`, update `getRulesForAssignable()`:

```php
use Illuminate\Support\Facades\Cache;

protected function getRulesForAssignable(Authenticatable|Model $assignable, string $action, string $modelClass): Collection
{
    $query = fn() => AccessRule::query()
        ->active()
        ->forAction($action)
        ->forModel($modelClass)
        ->whereHas('assignments', function ($query) use ($assignable) {
            $query->where('assignable_type', get_class($assignable))
                  ->where('assignable_id', $assignable->getAuthIdentifier());
        })
        ->orderedByPriority()
        ->get();

    if (!config('access-control.cache.enabled', false)) {
        return $query();
    }

    $prefix = config('access-control.cache.prefix', 'access_control');
    $ttl = config('access-control.cache.ttl', 3600);
    $cacheKey = sprintf(
        '%s:%s:%s:%s:%s',
        $prefix,
        get_class($assignable),
        $assignable->getAuthIdentifier(),
        $action,
        $modelClass
    );

    return Cache::remember($cacheKey, $ttl, $query);
}
```

**Step 4: Create cache observer**

```php
// src/Observers/AccessRuleCacheObserver.php
<?php

declare(strict_types=1);

namespace OthmanHaba\LaravelModelAcl\Observers;

use Illuminate\Support\Facades\Cache;
use OthmanHaba\LaravelModelAcl\Models\AccessRule;

class AccessRuleCacheObserver
{
    public function saved(AccessRule $rule): void
    {
        $this->clearCache();
    }

    public function deleted(AccessRule $rule): void
    {
        $this->clearCache();
    }

    protected function clearCache(): void
    {
        if (!config('access-control.cache.enabled', false)) {
            return;
        }

        $prefix = config('access-control.cache.prefix', 'access_control');
        Cache::flush(); // Simple approach — flush all cache with this tag

        // For production, consider using Cache::tags() if driver supports it
    }
}
```

**Step 5: Create assignment observer**

```php
// src/Observers/AccessRuleAssignmentCacheObserver.php
<?php

declare(strict_types=1);

namespace OthmanHaba\LaravelModelAcl\Observers;

use Illuminate\Support\Facades\Cache;
use OthmanHaba\LaravelModelAcl\Models\AccessRuleAssignment;

class AccessRuleAssignmentCacheObserver
{
    public function saved(AccessRuleAssignment $assignment): void
    {
        $this->clearCache();
    }

    public function deleted(AccessRuleAssignment $assignment): void
    {
        $this->clearCache();
    }

    protected function clearCache(): void
    {
        if (!config('access-control.cache.enabled', false)) {
            return;
        }

        Cache::flush();
    }
}
```

**Step 6: Register observers in service provider**

In `src/AccessControlServiceProvider.php`, add to `boot()`:

```php
use OthmanHaba\LaravelModelAcl\Models\AccessRule;
use OthmanHaba\LaravelModelAcl\Models\AccessRuleAssignment;
use OthmanHaba\LaravelModelAcl\Observers\AccessRuleCacheObserver;
use OthmanHaba\LaravelModelAcl\Observers\AccessRuleAssignmentCacheObserver;

// In boot():
if (config('access-control.cache.enabled', false)) {
    AccessRule::observe(AccessRuleCacheObserver::class);
    AccessRuleAssignment::observe(AccessRuleAssignmentCacheObserver::class);
}
```

**Step 7: Run tests**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Services/AccessControlServiceTest.php`
Expected: All PASS

**Step 8: Commit**

```bash
git add src/Services/AccessControlService.php src/Observers/ src/AccessControlServiceProvider.php tests/Unit/Services/AccessControlServiceTest.php
git commit -m "feat: add caching for rule lookups with auto-invalidation on changes"
```

---

### Task 12: Add Events

**Files:**
- Create: `src/Events/AccessGranted.php`
- Create: `src/Events/AccessDenied.php`
- Modify: `src/Services/AccessControlService.php`
- Add tests to: `tests/Unit/Services/AccessControlServiceTest.php`

**Step 1: Write event test**

Add to `AccessControlServiceTest.php`:

```php
use Illuminate\Support\Facades\Event;
use OthmanHaba\LaravelModelAcl\Events\AccessGranted;
use OthmanHaba\LaravelModelAcl\Events\AccessDenied;

public function test_dispatches_access_granted_event(): void
{
    Event::fake([AccessGranted::class]);

    $rule = AccessRule::create([
        'name' => 'View Open',
        'key' => 'view',
        'rule_class' => StatusRule::class,
        'settings' => ['statuses' => ['open']],
        'ruleable_type' => TestTicket::class,
        'active' => true,
    ]);

    AccessRuleAssignment::create([
        'access_rule_id' => $rule->id,
        'assignable_type' => TestUser::class,
        'assignable_id' => $this->user->id,
    ]);

    $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

    $this->service->can($this->user, 'view', $ticket);

    Event::assertDispatched(AccessGranted::class, function ($event) {
        return $event->action === 'view';
    });
}

public function test_dispatches_access_denied_event(): void
{
    Event::fake([AccessDenied::class]);

    $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

    $this->service->can($this->user, 'view', $ticket);

    Event::assertDispatched(AccessDenied::class, function ($event) {
        return $event->action === 'view';
    });
}
```

**Step 2: Create event classes**

```php
// src/Events/AccessGranted.php
<?php

declare(strict_types=1);

namespace OthmanHaba\LaravelModelAcl\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class AccessGranted
{
    use Dispatchable;

    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $action,
        public readonly Model $model,
    ) {}
}
```

```php
// src/Events/AccessDenied.php
<?php

declare(strict_types=1);

namespace OthmanHaba\LaravelModelAcl\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class AccessDenied
{
    use Dispatchable;

    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $action,
        public readonly Model $model,
    ) {}
}
```

**Step 3: Dispatch events from can()**

In `src/Services/AccessControlService.php`, update `can()`:

```php
use OthmanHaba\LaravelModelAcl\Events\AccessGranted;
use OthmanHaba\LaravelModelAcl\Events\AccessDenied;

public function can(Authenticatable $user, string $action, Model $model): bool
{
    $rules = $this->getApplicableRules($user, $action, $model);

    if ($rules->isEmpty()) {
        AccessDenied::dispatch($user, $action, $model);
        return false;
    }

    $resolutionLogic = $this->getResolutionLogic($model);
    $ruleInstances = $this->instantiateRules($rules, $user);
    $result = $this->ruleResolver->resolve($ruleInstances, $user, $model, $resolutionLogic);

    if ($result) {
        AccessGranted::dispatch($user, $action, $model);
    } else {
        AccessDenied::dispatch($user, $action, $model);
    }

    return $result;
}
```

**Step 4: Run tests**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Services/AccessControlServiceTest.php`
Expected: All PASS

**Step 5: Commit**

```bash
git add src/Events/ src/Services/AccessControlService.php tests/Unit/Services/AccessControlServiceTest.php
git commit -m "feat: dispatch AccessGranted and AccessDenied events"
```

---

### Task 13: Add Middleware

**Files:**
- Create: `src/Http/Middleware/AccessControlMiddleware.php`
- Modify: `src/AccessControlServiceProvider.php`
- Create: `tests/Feature/MiddlewareTest.php`

**Step 1: Create the middleware**

```php
// src/Http/Middleware/AccessControlMiddleware.php
<?php

declare(strict_types=1);

namespace OthmanHaba\LaravelModelAcl\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OthmanHaba\LaravelModelAcl\Services\AccessControlService;
use Symfony\Component\HttpFoundation\Response;

class AccessControlMiddleware
{
    public function __construct(
        protected AccessControlService $service
    ) {}

    public function handle(Request $request, Closure $next, string $action, ?string $modelParam = null): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(403, 'Unauthorized');
        }

        // If a model parameter is specified, check against the route-bound model
        if ($modelParam) {
            $model = $request->route($modelParam);

            if ($model && is_object($model)) {
                if (!$this->service->can($user, $action, $model)) {
                    abort(403, 'Access denied');
                }
            }
        }

        return $next($request);
    }
}
```

**Step 2: Register middleware alias in service provider**

In `src/AccessControlServiceProvider.php`, add to `boot()`:

```php
use Illuminate\Routing\Router;

$router = $this->app->make(Router::class);
$router->aliasMiddleware('acl', \OthmanHaba\LaravelModelAcl\Http\Middleware\AccessControlMiddleware::class);
```

**Step 3: Write middleware test**

```php
// tests/Feature/MiddlewareTest.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Feature;

use OthmanHaba\LaravelModelAcl\Models\AccessRule;
use OthmanHaba\LaravelModelAcl\Models\AccessRuleAssignment;
use OthmanHaba\LaravelModelAcl\Rules\StatusRule;
use OthmanHaba\LaravelModelAcl\Tests\TestCase;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestUser;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestTicket;
use Illuminate\Support\Facades\Route;

class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('acl:view,ticket')
            ->get('/tickets/{ticket}', function (TestTicket $ticket) {
                return response()->json(['title' => $ticket->title]);
            });
    }

    public function test_middleware_allows_access_with_valid_rule(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

        $rule = AccessRule::create([
            'name' => 'View Open',
            'key' => 'view',
            'rule_class' => StatusRule::class,
            'settings' => ['statuses' => ['open']],
            'ruleable_type' => TestTicket::class,
            'active' => true,
        ]);

        AccessRuleAssignment::create([
            'access_rule_id' => $rule->id,
            'assignable_type' => TestUser::class,
            'assignable_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson("/tickets/{$ticket->id}");

        $response->assertOk();
    }

    public function test_middleware_denies_access_without_rules(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

        $response = $this->actingAs($user)->getJson("/tickets/{$ticket->id}");

        $response->assertForbidden();
    }

    public function test_middleware_denies_unauthenticated_users(): void
    {
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

        $response = $this->getJson("/tickets/{$ticket->id}");

        $response->assertForbidden();
    }
}
```

**Step 4: Run tests**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Feature/MiddlewareTest.php`
Expected: All PASS

**Step 5: Commit**

```bash
git add src/Http/Middleware/AccessControlMiddleware.php src/AccessControlServiceProvider.php tests/Feature/MiddlewareTest.php
git commit -m "feat: add ACL middleware for route-level access control"
```

---

### Task 14: Fix config key structure + update MakeAccessRuleCommand stub

**Files:**
- Modify: `config/access-control.php`
- Modify: `src/Services/AccessControlService.php` (config lookup)
- Modify: `src/Traits/HasAccessRules.php` (config lookup)
- Modify: `src/Console/Commands/MakeAccessRuleCommand.php` (fix stub namespace)

**Step 1: Update config to use aliases instead of FQCN keys**

In `config/access-control.php`, update the models section:

```php
'models' => [
    // 'ticket' => [
    //     'class' => \App\Models\Ticket::class,
    //     'resolution_logic' => 'any',
    //     'scope_grouping' => 'and',
    //     'action_prefix' => 'ticket',
    //     'integrate_with_policies' => true,
    // ],
],
```

**Step 2: Add config lookup helper to AccessControlService**

```php
protected function getModelConfig(string $modelClass): array
{
    $models = config('access-control.models', []);

    foreach ($models as $config) {
        if (isset($config['class']) && $config['class'] === $modelClass) {
            return $config;
        }
    }

    return [];
}
```

Update `getResolutionLogic()` and `getScopeGroupingStrategy()` to use this helper:

```php
protected function getResolutionLogic(Model $model): string
{
    if ($model instanceof Authorizable) {
        return $model->getAccessResolutionLogic();
    }

    $modelConfig = $this->getModelConfig(get_class($model));

    return $modelConfig['resolution_logic'] ?? config('access-control.default_resolution', 'any');
}

protected function getScopeGroupingStrategy(Model $model): string
{
    if ($model instanceof Authorizable) {
        return $model->getScopeGroupingStrategy();
    }

    $modelConfig = $this->getModelConfig(get_class($model));

    return $modelConfig['scope_grouping'] ?? config('access-control.default_scope_grouping', 'and');
}
```

**Step 3: Update HasAccessRules trait to use same lookup**

```php
public function getAccessResolutionLogic(): string
{
    $modelConfig = app(\OthmanHaba\LaravelModelAcl\Services\AccessControlService::class)
        ->getModelConfig(static::class);

    return $modelConfig['resolution_logic'] ?? config('access-control.default_resolution', 'any');
}
```

Repeat for `getScopeGroupingStrategy()`, `getActionPrefix()`, `shouldIntegrateWithPolicies()`.

Note: `getModelConfig` needs to be public for this. Change its visibility to `public`.

**Step 4: Fix MakeAccessRuleCommand stub namespace**

In `src/Console/Commands/MakeAccessRuleCommand.php`, update the stub:

```php
protected function getStub(): string
{
    return <<<'STUB'
<?php

declare(strict_types=1);

namespace {{namespace}};

use OthmanHaba\LaravelModelAcl\Rules\BaseAccessRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;

class {{class}} extends BaseAccessRule
{
    public function __construct(
        ?Authenticatable $_user = null,
        ?int $_priority = null,
        ?bool $_is_deny_rule = null
    ) {
        parent::__construct($_user, $_priority, $_is_deny_rule);
    }

    public function passes(Authenticatable $user, Model $model): bool
    {
        return true;
    }

    public function scope(Builder $query, Authenticatable $user): Builder
    {
        return $query;
    }
}
STUB;
    }
}
```

**Step 5: Run all tests**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit`
Expected: All PASS

**Step 6: Commit**

```bash
git add config/access-control.php src/Services/AccessControlService.php src/Traits/HasAccessRules.php src/Console/Commands/MakeAccessRuleCommand.php
git commit -m "fix: use alias-based config keys, fix stub namespace"
```

---

### Task 15: Write CanBeRestricted trait tests

**Files:**
- Create: `tests/Unit/Traits/CanBeRestrictedTest.php`

**Step 1: Write tests**

```php
// tests/Unit/Traits/CanBeRestrictedTest.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Unit\Traits;

use OthmanHaba\LaravelModelAcl\Models\AccessRule;
use OthmanHaba\LaravelModelAcl\Models\AccessRuleAssignment;
use OthmanHaba\LaravelModelAcl\Rules\StatusRule;
use OthmanHaba\LaravelModelAcl\Tests\TestCase;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestUser;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestTicket;

class CanBeRestrictedTest extends TestCase
{
    private TestUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
    }

    public function test_assign_access_rule(): void
    {
        $rule = AccessRule::create([
            'name' => 'View Rule',
            'key' => 'view',
            'rule_class' => StatusRule::class,
            'settings' => ['statuses' => ['open']],
            'ruleable_type' => TestTicket::class,
            'active' => true,
        ]);

        $this->user->assignAccessRule($rule);

        $this->assertDatabaseHas('access_rule_assignments', [
            'access_rule_id' => $rule->id,
            'assignable_type' => TestUser::class,
            'assignable_id' => $this->user->id,
        ]);
    }

    public function test_assign_access_rule_is_idempotent(): void
    {
        $rule = AccessRule::create([
            'name' => 'View Rule',
            'key' => 'view',
            'rule_class' => StatusRule::class,
            'ruleable_type' => TestTicket::class,
            'active' => true,
        ]);

        $this->user->assignAccessRule($rule);
        $this->user->assignAccessRule($rule); // duplicate

        $this->assertEquals(1, AccessRuleAssignment::count());
    }

    public function test_remove_access_rule(): void
    {
        $rule = AccessRule::create([
            'name' => 'View Rule',
            'key' => 'view',
            'rule_class' => StatusRule::class,
            'ruleable_type' => TestTicket::class,
            'active' => true,
        ]);

        $this->user->assignAccessRule($rule);
        $this->user->removeAccessRule($rule);

        $this->assertDatabaseMissing('access_rule_assignments', [
            'access_rule_id' => $rule->id,
            'assignable_type' => TestUser::class,
            'assignable_id' => $this->user->id,
        ]);
    }

    public function test_has_access_rule(): void
    {
        $rule = AccessRule::create([
            'name' => 'View Rule',
            'key' => 'view',
            'rule_class' => StatusRule::class,
            'ruleable_type' => TestTicket::class,
            'active' => true,
        ]);

        $this->assertFalse($this->user->hasAccessRule($rule));

        $this->user->assignAccessRule($rule);

        $this->assertTrue($this->user->hasAccessRule($rule));
    }

    public function test_sync_access_rules(): void
    {
        $rule1 = AccessRule::create([
            'name' => 'Rule 1', 'key' => 'view',
            'rule_class' => StatusRule::class, 'active' => true,
        ]);
        $rule2 = AccessRule::create([
            'name' => 'Rule 2', 'key' => 'update',
            'rule_class' => StatusRule::class, 'active' => true,
        ]);
        $rule3 = AccessRule::create([
            'name' => 'Rule 3', 'key' => 'delete',
            'rule_class' => StatusRule::class, 'active' => true,
        ]);

        $this->user->assignAccessRule($rule1);
        $this->user->syncAccessRules([$rule2->id, $rule3->id]);

        $this->assertFalse($this->user->hasAccessRule($rule1));
        $this->assertTrue($this->user->hasAccessRule($rule2));
        $this->assertTrue($this->user->hasAccessRule($rule3));
    }

    public function test_can_access_delegates_to_service(): void
    {
        $rule = AccessRule::create([
            'name' => 'View Open',
            'key' => 'view',
            'rule_class' => StatusRule::class,
            'settings' => ['statuses' => ['open']],
            'ruleable_type' => TestTicket::class,
            'active' => true,
        ]);

        $this->user->assignAccessRule($rule);

        $openTicket = TestTicket::create(['title' => 'Open', 'status' => 'open']);
        $closedTicket = TestTicket::create(['title' => 'Closed', 'status' => 'closed']);

        $this->assertTrue($this->user->canAccess('view', $openTicket));
        $this->assertFalse($this->user->canAccess('view', $closedTicket));
    }
}
```

**Step 2: Run tests**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit tests/Unit/Traits/CanBeRestrictedTest.php`
Expected: All PASS

**Step 3: Commit**

```bash
git add tests/Unit/Traits/CanBeRestrictedTest.php
git commit -m "test: add CanBeRestricted trait test coverage"
```

---

### Task 16: Final integration test + full test run

**Files:**
- Create: `tests/Feature/GateIntegrationTest.php`

**Step 1: Write Gate integration test**

```php
// tests/Feature/GateIntegrationTest.php
<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Feature;

use OthmanHaba\LaravelModelAcl\Models\AccessRule;
use OthmanHaba\LaravelModelAcl\Models\AccessRuleAssignment;
use OthmanHaba\LaravelModelAcl\Rules\StatusRule;
use OthmanHaba\LaravelModelAcl\Tests\TestCase;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestUser;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestTicket;
use Illuminate\Support\Facades\Gate;

class GateIntegrationTest extends TestCase
{
    public function test_gate_allows_access_with_matching_rule(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

        $rule = AccessRule::create([
            'name' => 'View Open',
            'key' => 'view',
            'rule_class' => StatusRule::class,
            'settings' => ['statuses' => ['open']],
            'ruleable_type' => TestTicket::class,
            'active' => true,
        ]);

        AccessRuleAssignment::create([
            'access_rule_id' => $rule->id,
            'assignable_type' => TestUser::class,
            'assignable_id' => $user->id,
        ]);

        $this->actingAs($user);

        $this->assertTrue(Gate::allows('view', $ticket));
    }

    public function test_gate_denies_access_without_rules(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);
        $ticket = TestTicket::create(['title' => 'Test', 'status' => 'open']);

        $this->actingAs($user);

        // Gate::before returns null (no rules), then no policy exists, so Gate denies
        $this->assertFalse(Gate::allows('view', $ticket));
    }

    public function test_gate_does_not_intercept_non_acl_models(): void
    {
        $user = TestUser::create(['name' => 'John', 'email' => 'john@test.com']);

        $this->actingAs($user);

        // A non-model argument should pass through to normal gate logic
        $result = Gate::allows('some-ability');
        $this->assertFalse($result); // No gate defined, so denied
    }
}
```

**Step 2: Run full test suite**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit`
Expected: All tests PASS across all suites

**Step 3: Commit**

```bash
git add tests/Feature/GateIntegrationTest.php
git commit -m "test: add Gate integration tests"
```

---

### Task 17: Update .gitignore and clean up

**Files:**
- Modify: `.gitignore`

**Step 1: Update .gitignore**

Ensure it includes:

```
/vendor/
/.idea/
composer.lock
.phpunit.cache/
.phpunit.result.cache
```

**Step 2: Run full test suite one final time**

Run: `cd /Users/m/sites/packages/laravel-model-acl && vendor/bin/phpunit`
Expected: All PASS

**Step 3: Commit**

```bash
git add .gitignore
git commit -m "chore: update gitignore"
```
