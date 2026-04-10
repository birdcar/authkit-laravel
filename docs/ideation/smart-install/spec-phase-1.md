# Implementation Spec: Smart Install - Phase 1

**PRD**: ./prd-phase-1.md
**Estimated Effort**: M (Medium)

## Technical Approach

Phase 1 focuses on building the detection infrastructure and CLI flags that will power the intelligent installer. We'll create a dedicated `EnvironmentDetector` service class that encapsulates all detection logic, returning a `DetectionResult` value object that the install command can use to make decisions.

The key architectural decision is to make detection completely read-only and side-effect free. This allows us to run detection first, display results to the user, and only then take action. It also makes the detection logic highly testable.

For CLI flags, we'll add `--mini` and update the existing `--force` flag behavior. The `--mini` flag is the simplest mode - it just publishes config and shows instructions. The `--force` flag will be enhanced to skip all confirmation prompts.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `src/Support/EnvironmentDetector.php` | Service class that detects existing auth setup |
| `src/Support/DetectionResult.php` | Value object holding all detection findings |
| `tests/Unit/EnvironmentDetectorTest.php` | Unit tests for detection logic |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `src/Commands/InstallCommand.php` | Add --mini flag, inject EnvironmentDetector, display detection before install |
| `src/AuthKitServiceProvider.php` | Register EnvironmentDetector as singleton |
| `tests/Feature/InstallCommandTest.php` | Add tests for --mini and --force modes |

### Deleted Files

None.

## Implementation Details

### EnvironmentDetector Service

**Pattern to follow**: Follow Laravel's service pattern with dependency injection via constructor.

**Overview**: The detector checks various indicators to determine the user's starting point. Each check is a separate method that returns a simple result, and all results are collected into a DetectionResult.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

class EnvironmentDetector
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    public function detect(): DetectionResult
    {
        return new DetectionResult(
            hasLaravelWorkos: $this->detectLaravelWorkos(),
            hasBreeze: $this->detectBreeze(),
            hasJetstream: $this->detectJetstream(),
            hasFortify: $this->detectFortify(),
            hasExistingWorkosConfig: $this->detectExistingWorkosConfig(),
            hasServicesWorkosConfig: $this->detectServicesWorkosConfig(),
            envVars: $this->detectEnvVars(),
        );
    }

    private function detectLaravelWorkos(): bool
    {
        // Check composer.json for laravel/workos
    }

    private function detectBreeze(): bool
    {
        // Check composer.json for laravel/breeze
    }

    private function detectJetstream(): bool
    {
        // Check composer.json for laravel/jetstream
    }

    private function detectFortify(): bool
    {
        // Check composer.json for laravel/fortify
    }

    private function detectExistingWorkosConfig(): bool
    {
        // Check if config/workos.php exists
    }

    private function detectServicesWorkosConfig(): bool
    {
        // Check if config/services.php has workos key
    }

    private function detectEnvVars(): array
    {
        // Parse .env file for WORKOS_* vars
    }
}
```

**Key decisions**:
- Filesystem is injected to allow easy testing with virtual filesystems
- Base path is injected rather than using app_path() directly for testability
- Each detection method is isolated and single-purpose

**Implementation steps**:
1. Create DetectionResult value object with readonly properties
2. Create EnvironmentDetector with constructor injection
3. Implement detectLaravelWorkos() by parsing composer.json
4. Implement detectBreeze(), detectJetstream(), detectFortify() similarly
5. Implement detectExistingWorkosConfig() checking file existence
6. Implement detectServicesWorkosConfig() by parsing config/services.php
7. Implement detectEnvVars() by parsing .env file line by line
8. Register EnvironmentDetector in service provider

### DetectionResult Value Object

**Overview**: Immutable value object that holds all detection findings with helper methods for common queries.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Support;

final readonly class DetectionResult
{
    public function __construct(
        public bool $hasLaravelWorkos,
        public bool $hasBreeze,
        public bool $hasJetstream,
        public bool $hasFortify,
        public bool $hasExistingWorkosConfig,
        public bool $hasServicesWorkosConfig,
        /** @var array<string, string> */
        public array $envVars,
    ) {}

    public function hasExistingAuth(): bool
    {
        return $this->hasBreeze || $this->hasJetstream || $this->hasFortify;
    }

    public function hasAnyWorkosSetup(): bool
    {
        return $this->hasLaravelWorkos || $this->hasExistingWorkosConfig || $this->hasServicesWorkosConfig;
    }

    public function hasEnvVar(string $name): bool
    {
        return isset($this->envVars[$name]);
    }

    public function getEnvVar(string $name): ?string
    {
        return $this->envVars[$name] ?? null;
    }

    public function isFreshInstall(): bool
    {
        return !$this->hasExistingAuth() && !$this->hasAnyWorkosSetup();
    }
}
```

**Key decisions**:
- Use readonly class (PHP 8.2+) for immutability
- Provide convenience methods for common queries
- Keep data flat, not nested

**Implementation steps**:
1. Create class with constructor property promotion
2. Add helper methods for common queries
3. Ensure all properties are accessed via public readonly

### InstallCommand Updates

**Pattern to follow**: `src/Commands/InstallCommand.php` (current implementation)

**Overview**: Update the install command to use EnvironmentDetector, add --mini flag, and display detection results before taking action.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use WorkOS\AuthKit\Support\EnvironmentDetector;
use WorkOS\AuthKit\Support\DetectionResult;

class InstallCommand extends Command
{
    protected $signature = 'workos:install
        {--force : Overwrite existing configuration files}
        {--mini : Minimal install - config only with setup instructions}';

    protected $description = 'Install WorkOS AuthKit';

    public function __construct(
        private EnvironmentDetector $detector,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->detector->detect();

        $this->displayDetectionResults($result);

        if ($this->option('mini')) {
            return $this->handleMiniInstall($result);
        }

        // Full install (wizard in Phase 2)
        return $this->handleFullInstall($result);
    }

    private function displayDetectionResults(DetectionResult $result): void
    {
        // Display what was detected using Laravel console components
    }

    private function handleMiniInstall(DetectionResult $result): int
    {
        $this->publishConfig();
        $this->displayMiniInstructions($result);
        return self::SUCCESS;
    }

    private function handleFullInstall(DetectionResult $result): int
    {
        // Current install behavior (Phase 1)
        // Wizard behavior added in Phase 2
    }
}
```

**Key decisions**:
- Inject EnvironmentDetector via constructor (Laravel auto-resolves from container)
- Detection runs before any action is taken
- --mini is a completely separate code path

**Implementation steps**:
1. Add --mini to signature
2. Inject EnvironmentDetector in constructor
3. Add displayDetectionResults() method with formatted output
4. Add handleMiniInstall() that only publishes config
5. Add displayMiniInstructions() with comprehensive next steps
6. Refactor existing install logic into handleFullInstall()
7. Update service provider to bind EnvironmentDetector

## Testing Requirements

### Unit Tests

| Test File | Coverage |
|-----------|----------|
| `tests/Unit/EnvironmentDetectorTest.php` | All detection methods |

**Key test cases**:
- Fresh Laravel project returns isFreshInstall() = true
- Project with laravel/workos in composer.json returns hasLaravelWorkos = true
- Project with laravel/breeze in composer.json returns hasBreeze = true
- Project with config/workos.php returns hasExistingWorkosConfig = true
- Project with workos key in config/services.php returns hasServicesWorkosConfig = true
- .env with WORKOS_CLIENT_ID returns envVars with that key
- .env with WORKOS_REDIRECT_URL returns envVars with that key
- Missing files don't throw exceptions (graceful handling)

### Integration Tests

| Test File | Coverage |
|-----------|----------|
| `tests/Feature/InstallCommandTest.php` | CLI behavior |

**Key scenarios**:
- `workos:install --mini` publishes only config file
- `workos:install --mini` does NOT run migrations
- `workos:install --mini` displays next steps including env vars
- `workos:install --force` overwrites existing config without prompt
- `workos:install` displays detection summary before install
- Fresh install shows "No existing auth detected"

### Manual Testing

- [ ] Run `workos:install --mini` on fresh Laravel 12 app
- [ ] Run `workos:install --mini` on app created with WorkOS starter kit
- [ ] Run `workos:install --force` on app with existing config/workos.php
- [ ] Verify --mini output includes correct env var names

## Error Handling

| Error Scenario | Handling Strategy |
|----------------|-------------------|
| composer.json missing | Return empty detection (assume fresh project) |
| .env file missing | Return empty envVars array |
| config/services.php missing | Return hasServicesWorkosConfig = false |
| Malformed JSON in composer.json | Log warning, return false for that check |
| Permission denied reading files | Throw exception with helpful message |

## Validation Commands

```bash
# Type checking (via PHPStan)
./vendor/bin/phpstan analyse

# Linting (via Pint)
./vendor/bin/pint --test

# Unit tests
./vendor/bin/pest tests/Unit

# Feature tests
./vendor/bin/pest tests/Feature

# All tests
./vendor/bin/pest
```

## Rollout Considerations

- **Feature flag**: None needed - this is additive functionality
- **Monitoring**: N/A for CLI tool
- **Alerting**: N/A for CLI tool
- **Rollback plan**: Revert to previous InstallCommand if issues found

## Open Items

- [ ] Decide whether to also detect workos guard in config/auth.php
- [ ] Decide whether to detect workos_id column in users table migration

---

*This spec is ready for implementation. Follow the patterns and validate at each step.*
