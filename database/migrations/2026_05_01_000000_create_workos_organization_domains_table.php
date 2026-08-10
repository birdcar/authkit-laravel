<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workos_organization_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('workos_id')->unique();
            // WorkOS organization ID — not a local FK. The org model's physical
            // table name is app-configured and unknowable at package-migration
            // time, so every cross-reference is an Eloquent-level join on plain
            // WorkOS ID strings, never a database FOREIGN KEY.
            $table->string('organization_id')->index();
            $table->string('domain');
            // Opaque WorkOS-owned string, deliberately not enum-cast: the
            // organization_domain.verification_failed event payload carries no
            // top-level state at all (only `reason` + nested state), so the
            // column must accept whatever shape WorkOS actually sends.
            $table->string('state')->nullable();
            $table->string('verification_prefix')->nullable();
            $table->string('verification_token')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workos_organization_domains');
    }
};
