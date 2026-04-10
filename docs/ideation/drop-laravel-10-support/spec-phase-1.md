# Implementation Spec: Drop Laravel 10 Support

## Technical Approach
Direct file modifications to remove Laravel 10 support. No architectural changes needed.

## File Changes

### 1. `composer.json`

**Changes:**
```diff
 "require": {
     "php": "^8.2",
-    "illuminate/contracts": "^10.0|^11.0|^12.0",
-    "illuminate/support": "^10.0|^11.0|^12.0",
+    "illuminate/contracts": "^11.0|^12.0",
+    "illuminate/support": "^11.0|^12.0",
     "workos/workos-php": "^4.29"
 },
 "require-dev": {
-    "orchestra/testbench": "^8.0|^9.0|^10.0",
-    "pestphp/pest": "^2.0|^3.0",
+    "orchestra/testbench": "^9.0|^10.0",
+    "pestphp/pest": "^3.0",
     "laravel/pint": "^1.0",
     "phpstan/phpstan": "^1.0"
 },
```

### 2. `src/WorkOSServiceProvider.php`

**Remove import (line 7) if no longer needed elsewhere:**
```diff
-use Illuminate\Foundation\Application;
```

**Replace lines 235-245 (configurePublishing method):**
```diff
-        // publishesMigrations() was added in Laravel 11
-        // @phpstan-ignore-next-line Laravel 10 compatibility check
-        if (version_compare(Application::VERSION, '11.0', '>=')) {
-            $this->publishesMigrations([
-                __DIR__.'/../database/migrations' => database_path('migrations'),
-            ], 'workos-migrations');
-        } else {
-            $this->publishes([
-                __DIR__.'/../database/migrations' => database_path('migrations'),
-            ], 'workos-migrations');
-        }
+        $this->publishesMigrations([
+            __DIR__.'/../database/migrations' => database_path('migrations'),
+        ], 'workos-migrations');
```

### 3. `.github/README.md`

**Line 20:**
```diff
-- Laravel 10, 11, or 12
+- Laravel 11 or 12
```

## Testing Requirements

1. Run `composer validate` - must pass
2. Run `composer update` - must resolve dependencies
3. Run `composer test` - all tests must pass
4. Run `composer analyse` - PHPStan must pass

## Validation Commands

```bash
# Validate composer.json syntax
composer validate

# Update dependencies
composer update

# Run test suite
composer test

# Run static analysis
composer analyse

# Format code
composer format
```

## Error Handling
No new error handling needed. This is a removal of compatibility code.

## Rollback Plan
If issues arise, revert the changes via git:
```bash
git checkout -- composer.json src/WorkOSServiceProvider.php .github/README.md
composer update
```
