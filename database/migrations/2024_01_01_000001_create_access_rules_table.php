<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('access-control.tables.access_rules', 'access_rules');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key')->index(); // e.g., 'view_ticket', 'edit_post'
            $table->string('rule_class'); // Fully qualified class name
            $table->json('settings')->nullable(); // Parameters for the rule

            // Polymorphic relation to the model (nullable for global rules)
            $table->nullableMorphs('ruleable');

            $table->integer('priority')->default(0); // Higher = executed first
            $table->boolean('is_deny_rule')->default(false); // Negative rules
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Composite index for performance
            $table->index(['key', 'active', 'ruleable_type']);
        });
    }

    public function down(): void
    {
        $tableName = config('access-control.tables.access_rules', 'access_rules');
        Schema::dropIfExists($tableName);
    }
};
