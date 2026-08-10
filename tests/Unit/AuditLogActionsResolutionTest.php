<?php

declare(strict_types=1);

use Authkit\Authkit\Attributes\AuditActions;
use Authkit\Authkit\Concerns\HasAuditLogs;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Post;

// Test path: none (pure PHP) — action-name resolution never touches the wire.

class AuditResolutionDefaultNote extends Model
{
    use HasAuditLogs;

    protected $table = 'posts';
}

class AuditResolutionPropertyNote extends Model
{
    use HasAuditLogs;

    protected $table = 'posts';

    /** @var array<string, string> */
    protected array $auditActions = ['delete' => 'note.purge'];
}

class AuditResolutionFullPropertyNote extends Model
{
    use HasAuditLogs;

    protected $table = 'posts';

    /** @var array<string, string> */
    protected array $auditActions = [
        'create' => 'note.written',
        'update' => 'note.edited',
        'delete' => 'note.purged',
        'archive' => 'note.shelved',
        'restore' => 'note.revived',
    ];
}

#[AuditActions(create: 'note.published', archive: 'note.hidden')]
class AuditResolutionAttributeNote extends Model
{
    use HasAuditLogs;

    protected $table = 'posts';
}

#[AuditActions(create: 'attribute.create')]
class AuditResolutionBothNote extends Model
{
    use HasAuditLogs;

    protected $table = 'posts';

    /** @var array<string, string> */
    protected array $auditActions = ['create' => 'property.create'];
}

it('derives default action names from the snake-cased class basename', function (): void {
    expect((new AuditResolutionDefaultNote)->auditLogActions())->toBe([
        'create' => 'audit_resolution_default_note.create',
        'update' => 'audit_resolution_default_note.update',
        'delete' => 'audit_resolution_default_note.delete',
        'archive' => 'audit_resolution_default_note.archive',
        'restore' => 'audit_resolution_default_note.restore',
    ])->and((new Post)->auditLogActions()['create'])->toBe('post.create');
});

it('merges a partial $auditActions property override over the defaults', function (): void {
    $actions = (new AuditResolutionPropertyNote)->auditLogActions();

    expect($actions['delete'])->toBe('note.purge')
        ->and($actions['create'])->toBe('audit_resolution_property_note.create')
        ->and($actions['restore'])->toBe('audit_resolution_property_note.restore');
});

it('honors a full $auditActions property override', function (): void {
    expect((new AuditResolutionFullPropertyNote)->auditLogActions())->toBe([
        'create' => 'note.written',
        'update' => 'note.edited',
        'delete' => 'note.purged',
        'archive' => 'note.shelved',
        'restore' => 'note.revived',
    ]);
});

it('merges non-null #[AuditActions] attribute arguments over the defaults', function (): void {
    $actions = (new AuditResolutionAttributeNote)->auditLogActions();

    expect($actions['create'])->toBe('note.published')
        ->and($actions['archive'])->toBe('note.hidden')
        ->and($actions['update'])->toBe('audit_resolution_attribute_note.update');
});

it('prefers the $auditActions property when both property and attribute are present', function (): void {
    expect((new AuditResolutionBothNote)->auditLogActions()['create'])->toBe('property.create');
});
