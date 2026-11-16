<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cc_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('cc_workflows')->onDelete('cascade');
            $table->string('step_key', 100);
            $table->string('step_name', 150);
            $table->string('step_type', 30)->index(); // question, task, approval, condition, action, end
            $table->text('description')->nullable();
            $table->integer('sequence')->default(1)->index();
            $table->string('assigned_role', 100)->nullable()->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->json('form_schema')->nullable();
            $table->json('conditions')->nullable();
            $table->json('actions')->nullable();
            $table->foreignId('next_step_id')->nullable()->constrained('cc_workflow_steps')->nullOnDelete();
            $table->boolean('is_required')->default(true);
            $table->boolean('allow_skip')->default(false);
            $table->integer('sla_minutes')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['workflow_id', 'step_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cc_workflow_steps');
    }
};
