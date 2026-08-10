<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_demo_records', function (Blueprint $table): void {
            $table->id();
            // text(), never string(): a VARCHAR would truncate the envelope and
            // corrupt the AES-GCM tag, surfacing as a decrypt-time
            // RuntimeException instead of a save-time error (spec-phase-9 §8).
            $table->text('secret')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_demo_records');
    }
};
