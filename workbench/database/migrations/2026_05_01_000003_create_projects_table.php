<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // A plain string holding the WorkOS org ID directly — deliberately
            // not an FK to the workbench organizations table, keeping this
            // FGA fixture independent of the org projection's timing.
            $table->string('organization_id');
            // Backs the SoftDeletableProject fixture (Failure Mode 8); the
            // plain Project model ignores it.
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
