<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use YourVendor\LaravelModelAcl\Services\AccessControlService;
use YourVendor\LaravelModelAcl\Services\RuleResolver;
use YourVendor\LaravelModelAcl\Contracts\RuleResolverContract;
use YourVendor\LaravelModelAcl\Console\Commands\MakeAccessRuleCommand;

class AccessControlServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/access-control.php',
            'access-control'
        );

        // Bind contracts
        $this->app->singleton(RuleResolverContract::class, RuleResolver::class);
        $this->app->singleton(AccessControlService::class);

        // Register alias
        $this->app->alias(AccessControlService::class, 'access-control');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/access-control.php' => config_path('access-control.php'),
        ], 'access-control-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'access-control-migrations');

        // Load migrations from package
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeAccessRuleCommand::class,
            ]);
        }

        // Register Gate integration
        if (config('access-control.integrations.laravel_gates', true)) {
            $this->registerGateIntegration();
        }

        // Register Policy integration
        if (config('access-control.integrations.laravel_policies', true)) {
            $this->registerPolicyIntegration();
        }
    }

    /**
     * Register Laravel Gate integration
     */
    protected function registerGateIntegration(): void
    {
        Gate::before(function ($user, $ability, $arguments = []) {
            // Only intercept if first argument is a model instance
            if (empty($arguments) || !is_object($arguments[0])) {
                return null; // Let normal gates/policies handle it
            }

            $model = $arguments[0];

            // Check if model uses HasAccessRules trait
            if (!in_array(\YourVendor\LaravelModelAcl\Traits\HasAccessRules::class, class_uses_recursive($model))) {
                return null; // Not managed by this package
            }

            // Check if model wants policy integration
            if (method_exists($model, 'shouldIntegrateWithPolicies') && !$model->shouldIntegrateWithPolicies()) {
                return null;
            }

            // Use our access control service
            $service = app(AccessControlService::class);
            $action = $ability;

            try {
                $canAccess = $service->can($user, $action, $model);
                return $canAccess ? true : null; // Return null to allow policies to run
            } catch (\Exception $e) {
                // Log error if logging is enabled
                if (config('access-control.logging.enabled', false)) {
                    logger()->channel(config('access-control.logging.channel', 'stack'))
                           ->error('Access control error: ' . $e->getMessage());
                }
                return null;
            }
        });
    }

    /**
     * Register Laravel Policy integration
     */
    protected function registerPolicyIntegration(): void
    {
        // Policy integration is handled through Gate::before above
        // Policies will still run if our gate check returns null
    }
}
