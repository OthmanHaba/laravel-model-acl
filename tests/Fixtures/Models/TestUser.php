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
