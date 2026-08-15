<?php

declare(strict_types=1);

namespace OthmanHaba\LaravelModelAcl;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use OthmanHaba\LaravelModelAcl\Services\AccessControlService;
use OthmanHaba\LaravelModelAcl\Services\RuleResolver;
use OthmanHaba\LaravelModelAcl\Contracts\RuleResolverContract;
use OthmanHaba\LaravelModelAcl\Console\Commands\MakeAccessRuleCommand;

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

        // Invalidate cached rule lookups when rules or their assignments change.
        // Assignment events cover writes made outside the CanBeRestricted trait
        // (e.g. an admin UI editing assignments directly).
        if (config('access-control.cache.enabled', false)) {
            \OthmanHaba\LaravelModelAcl\Models\AccessRule::saved(fn() => AccessControlService::flushCache());
            \OthmanHaba\LaravelModelAcl\Models\AccessRule::deleted(fn() => AccessControlService::flushCache());
            \OthmanHaba\LaravelModelAcl\Models\AccessRuleAssignment::saved(fn() => AccessControlService::flushCache());
            \OthmanHaba\LaravelModelAcl\Models\AccessRuleAssignment::deleted(fn() => AccessControlService::flushCache());
        }

        // Register `acl` route middleware alias
        $this->app['router']->aliasMiddleware(
            'acl',
            \OthmanHaba\LaravelModelAcl\Http\Middleware\AccessControlMiddleware::class
        );

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
            if (!in_array(\OthmanHaba\LaravelModelAcl\Traits\HasAccessRules::class, class_uses_recursive($model))) {
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
                // true = grant, false = deny (authoritative), null = no rules -> let policies run
                return $service->decide($user, $action, $model);
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
