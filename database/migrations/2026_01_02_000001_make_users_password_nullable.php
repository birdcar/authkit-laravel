<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split out from add_workos_id_to_users_table on purpose: this one alters a column
 * the package does not own, and a consumer reviewing published migrations should
 * see that from the file name rather than discover it inside another migration.
 *
 * A WorkOS-authenticated user has no local password, but Laravel's default users
 * table declares the column NOT NULL — without this, the very first login through
 * findOrCreateForWorkosUser() fails with an integrity-constraint violation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately not reversed: rows created while this was applied
        // legitimately have no password, so restoring NOT NULL would fail on
        // exactly the accounts this package created.
    }
};
