<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('access-control.tables.assignments', 'access_rule_assignments');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_rule_id')
                  ->constrained(config('access-control.tables.access_rules', 'access_rules'))
                  ->onDelete('cascade');

            // Polymorphic relation to User, Employee, Role, etc.
            $table->morphs('assignable');

            $table->timestamps();

            // Unique constraint - each rule can only be assigned once to each entity
            $table->unique(['access_rule_id', 'assignable_type', 'assignable_id'], 'unique_assignment');

            // Performance index
            $table->index(['assignable_type', 'assignable_id']);
        });
    }

    public function down(): void
    {
        $tableName = config('access-control.tables.assignments', 'access_rule_assignments');
        Schema::dropIfExists($tableName);
    }
};
