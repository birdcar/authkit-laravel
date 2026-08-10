<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-row durable cursor for the authkit:work poller — the ONLY
        // table this phase introduces (the contract's projection-boundary
        // decision whitelists "sync bookkeeping (events cursor)" and nothing
        // else). Single-row semantics are enforced by application logic
        // (WorkosEventCursor::current()'s firstOrCreate), not a DB constraint:
        // the poller's cache lock guarantees a single writer.
        Schema::create('workos_event_cursor', function (Blueprint $table): void {
            $table->id();
            $table->string('last_event_id')->nullable();
            $table->timestamp('last_event_occurred_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workos_event_cursor');
    }
};
