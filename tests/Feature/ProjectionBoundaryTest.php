<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Makes the "WorkOS stays canonical" doctrine mechanically falsifiable: local
 * WorkOS-shaped state (any table carrying a workos_id or external_id column)
 * is limited to the explicitly declared projections, in both directions — an
 * undeclared new table fails the build (scope creep), and a whitelisted table
 * gone missing fails it too (a promised projection silently dropped).
 *
 * Detection is deliberately column-name-based and false-positive-favoring: an
 * app-owned table reusing `external_id` for an unrelated integration would be
 * flagged. That rare false alarm costs less than a real silent projection
 * leak — do not "fix" it by narrowing the column check.
 *
 * A plain feature test, not a Pest arch() rule: arch() is static analysis and
 * cannot introspect a runtime database schema, so this lives here against the
 * fully-migrated Testbench app (package + workbench migrations).
 */
beforeEach(function (): void {
    $this->migratePackageDatabase();
});

test('ProjectionBoundary: local WorkOS-shaped state is limited to the declared whitelist', function (): void {
    // The five declared projections, on their landed table names. The
    // workos_event_cursor row is sync bookkeeping — not itself claims-shaped
    // (no workos_id/external_id column), but explicitly declared allowed and
    // asserted present so the poller's resume story can't silently vanish.
    $whitelist = [
        'users',                          // user projection (workos_id)
        'organizations',                  // org projection — workbench model table
        'workos_organization_domains',    // domains projection
        'workos_memberships',             // org-membership projection
        'workos_event_cursor',            // events-pipeline sync bookkeeping
    ];

    $offenders = [];

    foreach (Schema::getTables() as $tableInfo) {
        $table = is_array($tableInfo) ? ($tableInfo['name'] ?? null) : null;

        if (! is_string($table)) {
            continue;
        }

        $columnNames = collect(Schema::getColumns($table))
            ->map(fn (array $column): mixed => $column['name'] ?? null);

        $isWorkosShaped = $columnNames->contains('workos_id') || $columnNames->contains('external_id');

        if ($isWorkosShaped && ! in_array($table, $whitelist, true)) {
            $offenders[] = $table;
        }
    }

    expect($offenders)->toBeEmpty(
        'Undeclared WorkOS-shaped table(s) found, violating the projection boundary: '.implode(', ', $offenders),
    );

    foreach ($whitelist as $table) {
        expect(Schema::hasTable($table))->toBeTrue(
            "Declared projection table [$table] is missing — a promised projection was never migrated.",
        );
    }
});
