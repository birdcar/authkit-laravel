<?php

declare(strict_types=1);

namespace Authkit\Authkit\Concerns;

use Authkit\Authkit\Attributes\AuditActions;
use Authkit\Authkit\Facades\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Dead-simple audit logging for Eloquent lifecycle actions: create, update,
 * delete, archive (soft delete), restore. Action names default to
 * "{snake-case class basename}.{lifecycle}" and can be overridden per model
 * via a $auditActions property or the #[AuditActions] class attribute —
 * partial overrides merge over the defaults.
 *
 * Every recorded action routes through AuditLog::log(), which resolves
 * actor/organization context eagerly in this process and queues the wire
 * call — a mutation outside any organization context throws
 * MissingOrganizationContextException rather than guessing.
 *
 * @phpstan-require-extends Model
 */
trait HasAuditLogs
{
    protected static function bootHasAuditLogs(): void
    {
        static::created(function (self $model): void {
            $model->recordAuditLogAction('create');
        });

        static::updated(function (self $model): void {
            $model->recordAuditLogAction('update');
        });

        static::deleted(function (self $model): void {
            // A SoftDeletes model's delete() is an archive; its forceDelete()
            // sets $forceDeleting BEFORE firing `deleted` (and resets it
            // after), so isForceDeleting() is authoritative here. A model
            // without SoftDeletes has no isForceDeleting() → always delete.
            $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
            $isArchive = $usesSoftDeletes && ! $model->isForceDeleting();

            $model->recordAuditLogAction($isArchive ? 'archive' : 'delete');
        });

        // registerModelEvent rather than static::restored(): the restored()
        // registrar only exists on SoftDeletes models, and this trait must
        // boot cleanly on models without it. The event itself only ever fires
        // for SoftDeletes models, so the extra listener is inert elsewhere.
        static::registerModelEvent('restored', function (self $model): void {
            $model->recordAuditLogAction('restore');
        });
    }

    /**
     * Lifecycle => WorkOS action name. Resolution order: $auditActions
     * property > #[AuditActions] attribute > slug-based default; partial
     * overrides merge over the defaults.
     *
     * @return array<string, string>
     */
    public function auditLogActions(): array
    {
        $slug = $this->auditLogSlug();

        $actions = [
            'create' => "{$slug}.create",
            'update' => "{$slug}.update",
            'delete' => "{$slug}.delete",
            'archive' => "{$slug}.archive",
            'restore' => "{$slug}.restore",
        ];

        foreach ($this->auditLogActionOverrides() as $lifecycle => $action) {
            if (array_key_exists($lifecycle, $actions) && is_string($action) && $action !== '') {
                $actions[$lifecycle] = $action;
            }
        }

        return $actions;
    }

    /**
     * Default action-name prefix. Override to change (e.g. "blog_post"
     * instead of "post").
     */
    public function auditLogSlug(): string
    {
        return Str::snake(class_basename($this));
    }

    /**
     * Override to attach event metadata. Capped to the WorkOS limits
     * (50 keys, 500 chars per string value) via MetadataSanitizer before the
     * request leaves the process.
     *
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [];
    }

    protected function recordAuditLogAction(string $lifecycle): void
    {
        $key = $this->getKey();

        AuditLog::log(
            action: $this->auditLogActions()[$lifecycle],
            targets: [[
                'id' => is_scalar($key) ? (string) $key : '',
                'type' => $this->auditLogSlug(),
            ]],
            metadata: $this->auditMetadata(),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function auditLogActionOverrides(): array
    {
        $property = get_object_vars($this)['auditActions'] ?? null;

        if (is_array($property)) {
            return $property;
        }

        $attributes = (new ReflectionClass($this))->getAttributes(AuditActions::class);

        if ($attributes === []) {
            return [];
        }

        $attribute = $attributes[0]->newInstance();

        return array_filter([
            'create' => $attribute->create,
            'update' => $attribute->update,
            'delete' => $attribute->delete,
            'archive' => $attribute->archive,
            'restore' => $attribute->restore,
        ], static fn (?string $action): bool => $action !== null);
    }
}
