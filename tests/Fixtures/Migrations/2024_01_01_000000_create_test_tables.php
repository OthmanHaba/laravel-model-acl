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
