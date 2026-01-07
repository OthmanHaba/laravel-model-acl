# Installation & Setup Guide

## Quick Setup for Your Project

### 1. Install the Package

The package is already added to your `composer.json` and installed. If you need to reinstall:

```bash
composer update yourvendor/laravel-model-acl
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --provider="YourVendor\LaravelModelAcl\AccessControlServiceProvider" --tag=access-control-config
```

### 3. Publish Migrations

```bash
php artisan vendor:publish --provider="YourVendor\LaravelModelAcl\AccessControlServiceProvider" --tag=access-control-migrations
```

### 4. Run Migrations

```bash
php artisan migrate
```

### 5. Update Your Models

#### For the Ticket Model

```php
// app/Models/Ticket.php

use YourVendor\LaravelModelAcl\Traits\HasAccessRules;

class Ticket extends Model
{
    use HasAccessRules;

    // ... rest of your code
}
```

#### For the Employee Model

```php
// app/Models/Employee.php

use YourVendor\LaravelModelAcl\Traits\CanBeRestricted;

class Employee extends Authenticatable
{
    use CanBeRestricted;

    // ... rest of your code
}
```

### 6. Configure for Tickets

Edit `config/access-control.php`:

```php
'models' => [
    \App\Models\Ticket::class => [
        'resolution_logic' => 'any',
        'scope_grouping' => 'and',
        'action_prefix' => 'ticket',
        'integrate_with_policies' => true,
    ],
],

'fallback' => [
    \App\Models\Ticket::class => [
        'column' => 'assignee_id',
    ],
],
```

### 7. Migrate Existing Rules

Create a migration script to convert your old `TicketAccessRule` to new `AccessRule`:

```php
// database/migrations/2024_xx_xx_migrate_ticket_access_rules.php

use App\Models\TicketAccessRule as OldRule;
use YourVendor\LaravelModelAcl\Models\AccessRule;
use YourVendor\LaravelModelAcl\Models\AccessRuleAssignment;

public function up()
{
    // Get all old rules
    $oldRules = OldRule::with('employees')->get();

    foreach ($oldRules as $oldRule) {
        // Create new rule
        $newRule = AccessRule::create([
            'name' => $oldRule->name,
            'key' => $oldRule->key,
            'rule_class' => $oldRule->rule_class,
            'ruleable_type' => \App\Models\Ticket::class,
            'settings' => $oldRule->settings,
            'priority' => 0, // Set default priority
            'is_deny_rule' => false,
            'active' => $oldRule->active,
        ]);

        // Assign to employees
        foreach ($oldRule->employees as $employee) {
            AccessRuleAssignment::create([
                'access_rule_id' => $newRule->id,
                'assignable_type' => \App\Models\Employee::class,
                'assignable_id' => $employee->id,
            ]);
        }
    }
}
```

### 8. Update Your Service Usage

Replace `TicketAccessService` with the new package:

```php
// Old
use App\Services\TicketAccessService;
TicketAccessService::can($user, 'view', $ticket);

// New Option 1: Using the service
use YourVendor\LaravelModelAcl\Services\AccessControlService;
app(AccessControlService::class)->can($user, 'view', $ticket);

// New Option 2: Using trait (recommended)
$user->canAccess('view', $ticket);

// New Option 3: Using Laravel Gate (automatic)
auth()->user()->can('view', $ticket);
```

### 9. Update Query Filtering

```php
// Old
use App\Services\TicketAccessService;
$tickets = TicketAccessService::filterTickets($user, 'view', Ticket::query())->get();

// New
$tickets = Ticket::accessibleBy($user, 'view')->get();
```

## Testing the Package

### Create a Test Rule

```php
use YourVendor\LaravelModelAcl\Models\AccessRule;
use YourVendor\LaravelModelAcl\Rules\StatusRule;

$rule = AccessRule::create([
    'name' => 'View Pending Tickets',
    'key' => 'view_ticket',
    'rule_class' => StatusRule::class,
    'ruleable_type' => \App\Models\Ticket::class,
    'settings' => [
        'statuses' => ['pending', 'in_progress'],
    ],
    'priority' => 10,
    'active' => true,
]);

// Assign to an employee
$employee = Employee::first();
$employee->assignAccessRule($rule);

// Test it
$ticket = Ticket::where('status', 'pending')->first();
dd($employee->canAccess('view', $ticket)); // Should return true

$closedTicket = Ticket::where('status', 'closed')->first();
dd($employee->canAccess('view', $closedTicket)); // Should return false
```

## Benefits Over Old System

1. **Works with Any Model** - Not just tickets
2. **Priority System** - Control which rules apply first
3. **Deny Rules** - Explicitly block access
4. **Better Query Grouping** - Fixes the AND/OR logic issues
5. **User Attributes** - Rules can check user properties
6. **Laravel Integration** - Works with Gates and Policies
7. **Role Support** - Works with Spatie Permission
8. **Better Performance** - Caching support built-in
9. **Easier Testing** - Trait methods for quick access checks
10. **Extensible** - Easy to create new rule types

## Next Steps

1. Test the package with a simple rule
2. Migrate your existing rules
3. Update your controllers/services to use new methods
4. Create any custom rules you need
5. Configure per-model settings
6. Enable caching in production

## Need Help?

Check the README.md for full documentation and examples.
