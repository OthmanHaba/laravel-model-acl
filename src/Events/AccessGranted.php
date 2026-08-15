<?php

declare(strict_types=1);

namespace OthmanHaba\LaravelModelAcl\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

class AccessGranted
{
    use Dispatchable;

    /**
     * @param Collection $matchedRules Instantiated rule objects that were evaluated
     */
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $action,
        public readonly Model $model,
        public readonly Collection $matchedRules
    ) {}
}
