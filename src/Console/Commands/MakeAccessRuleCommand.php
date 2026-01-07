<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeAccessRuleCommand extends Command
{
    protected $signature = 'make:access-rule {name : The name of the rule class}
                                             {--model= : The model this rule applies to}';

    protected $description = 'Create a new access rule class';

    public function handle(): int
    {
        $name = $this->argument('name');
        $model = $this->option('model');

        // Ensure name ends with "Rule"
        if (!Str::endsWith($name, 'Rule')) {
            $name .= 'Rule';
        }

        // Determine path
        $path = app_path('Rules/Access/' . $name . '.php');
        $directory = dirname($path);

        // Create directory if it doesn't exist
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Check if file already exists
        if (file_exists($path)) {
            $this->error("Rule class {$name} already exists!");
            return self::FAILURE;
        }

        // Generate class content
        $stub = $this->getStub();
        $content = str_replace(
            ['{{namespace}}', '{{class}}', '{{model}}'],
            ['App\\Rules\\Access', $name, $model ?? 'Model'],
            $stub
        );

        // Write file
        file_put_contents($path, $content);

        $this->info("Access rule [{$name}] created successfully.");
        $this->info("Location: {$path}");

        return self::SUCCESS;
    }

    protected function getStub(): string
    {
        return <<<'STUB'
<?php

declare(strict_types=1);

namespace {{namespace}};

use YourVendor\LaravelModelAcl\Rules\BaseAccessRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;

class {{class}} extends BaseAccessRule
{
    /**
     * Constructor - define your rule parameters here
     */
    public function __construct(
        // Add your parameters here
        // Example: ?string $some_parameter = null,
        ?Authenticatable $_user = null,
        ?int $_priority = null,
        ?bool $_is_deny_rule = null
    ) {
        parent::__construct($_user, $_priority, $_is_deny_rule);

        // Initialize your parameters
        // Example: $this->someParameter = $some_parameter;
    }

    /**
     * Check if the rule passes for a specific model instance
     *
     * @param Authenticatable $user
     * @param Model $model
     * @return bool
     */
    public function passes(Authenticatable $user, Model $model): bool
    {
        // Implement your authorization logic here
        // Return true to grant access, false to deny

        return true;
    }

    /**
     * Apply query scope for filtering collections
     *
     * @param mixed $query
     * @param Authenticatable $user
     * @return mixed
     */
    public function scope($query, Authenticatable $user)
    {
        // Implement your query filtering logic here
        // Example: return $query->where('status', 'active');

        return $query;
    }
}
STUB;
    }
}
