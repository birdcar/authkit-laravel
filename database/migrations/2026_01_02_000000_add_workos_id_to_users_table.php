<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Nullable: accounts that predate WorkOS adoption are not linked yet.
            // Unique: this is the projection key both the guard's
            // retrieveByCredentials() lookup and the trait's find-or-create rely on.
            $table->string('workos_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('workos_id');
        });
    }
};
