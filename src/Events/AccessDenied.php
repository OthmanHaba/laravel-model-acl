<?php

declare(strict_types=1);

namespace OthmanHaba\LaravelModelAcl\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

class AccessDenied
{
    use Dispatchable;

    /**
     * @param Collection $evaluatedRules Instantiated rule objects that were evaluated
     */
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $action,
        public readonly Model $model,
        public readonly Collection $evaluatedRules
    ) {}
}
