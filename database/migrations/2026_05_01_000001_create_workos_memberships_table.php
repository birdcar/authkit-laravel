<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workos_memberships', function (Blueprint $table): void {
            // The membership's own WorkOS ID (om_...) is the only unique key.
            // A composite unique on (organization_id, user_id) was rejected: a
            // hard-deleted membership whose .deleted event was missed would turn
            // a later re-create for the same pair into a database error instead
            // of a harmless extra row the events pipeline eventually cleans up.
            $table->id();
            $table->string('workos_id')->unique();
            $table->string('organization_id'); // WorkOS organization ID
            $table->string('user_id'); // WorkOS user ID
            $table->string('role')->nullable(); // role slug
            $table->string('status')->default('active'); // active|inactive|pending
            $table->timestamps();

            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workos_memberships');
    }
};
