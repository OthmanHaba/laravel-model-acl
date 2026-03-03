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
