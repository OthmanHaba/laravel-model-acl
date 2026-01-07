<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Resolution Logic
    |--------------------------------------------------------------------------
    |
    | How should multiple rules be resolved when checking access?
    | Options: 'any', 'all', 'priority'
    |
    | - any: Grant access if ANY rule passes (OR logic)
    | - all: Grant access only if ALL rules pass (AND logic)
    | - priority: First matching rule wins (based on priority weight)
    |
    */
    'default_resolution' => env('ACCESS_CONTROL_RESOLUTION', 'any'),

    /*
    |--------------------------------------------------------------------------
    | Default Scope Grouping
    |--------------------------------------------------------------------------
    |
    | How should query scopes be combined when filtering collections?
    | Options: 'and', 'or'
    |
    | - and: Restrictive - all conditions must be met
    | - or: Additive - any condition grants access
    |
    */
    'default_scope_grouping' => env('ACCESS_CONTROL_SCOPE_GROUPING', 'and'),

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Customize the table names used by the package
    |
    */
    'tables' => [
        'access_rules' => 'access_rules',
        'assignments' => 'access_rule_assignments',
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    |
    | Enable or disable integrations with other packages
    |
    */
    'integrations' => [
        'laravel_gates' => env('ACCESS_CONTROL_GATES', true),
        'laravel_policies' => env('ACCESS_CONTROL_POLICIES', true),
        'spatie_permission' => env('ACCESS_CONTROL_SPATIE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model-Specific Configuration
    |--------------------------------------------------------------------------
    |
    | Configure access control behavior for specific models
    |
    | Example:
    | 'App\Models\Ticket' => [
    |     'resolution_logic' => 'any',
    |     'scope_grouping' => 'and',
    |     'action_prefix' => 'ticket',
    |     'integrate_with_policies' => true,
    | ],
    |
    */
    'models' => [
        // Add your model-specific configurations here
        // Example:
        // \App\Models\Ticket::class => [
        //     'resolution_logic' => 'any',
        //     'scope_grouping' => 'and',
        //     'action_prefix' => 'ticket',
        //     'integrate_with_policies' => true,
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Filtering
    |--------------------------------------------------------------------------
    |
    | When no rules are assigned, how should access be restricted?
    | Specify the column to filter by (typically user_id, created_by, etc.)
    |
    | Example:
    | 'App\Models\Ticket' => [
    |     'column' => 'assignee_id',
    | ],
    |
    */
    'fallback' => [
        // Add your fallback configurations here
        // Example:
        // \App\Models\Ticket::class => [
        //     'column' => 'assignee_id',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Standard Actions
    |--------------------------------------------------------------------------
    |
    | Define standard CRUD actions that will be used across models
    | These will be available in the rule builder and artisan commands
    |
    */
    'standard_actions' => [
        'view',
        'create',
        'update',
        'delete',
        'restore',
        'force_delete',
    ],

    /*
    |--------------------------------------------------------------------------
    | Built-in Rules
    |--------------------------------------------------------------------------
    |
    | Register built-in rule classes for quick reference
    |
    */
    'built_in_rules' => [
        'status' => \YourVendor\LaravelModelAcl\Rules\StatusRule::class,
        'date_range' => \YourVendor\LaravelModelAcl\Rules\DateRangeRule::class,
        'ownership' => \YourVendor\LaravelModelAcl\Rules\OwnershipRule::class,
        'attribute' => \YourVendor\LaravelModelAcl\Rules\AttributeRule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Enable caching for rule lookups to improve performance
    |
    */
    'cache' => [
        'enabled' => env('ACCESS_CONTROL_CACHE', true),
        'ttl' => env('ACCESS_CONTROL_CACHE_TTL', 3600), // seconds
        'prefix' => 'access_control',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Enable logging for access control checks (useful for debugging)
    |
    */
    'logging' => [
        'enabled' => env('ACCESS_CONTROL_LOGGING', false),
        'channel' => env('ACCESS_CONTROL_LOG_CHANNEL', 'stack'),
    ],
];
