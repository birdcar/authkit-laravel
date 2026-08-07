<?php

declare(strict_types=1);

namespace Authkit\Authkit\Tests\Support;

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class EmulateServer
{
    /**
     * Pinned to an exact version rather than a range: on Windows the shell
     * command runs through cmd.exe, where `^` is the escape character and
     * `@^0.6` would be silently mangled into `@0.6`.
     */
    private const string PACKAGE = '@workos/emulate@0.6.0';

    private ?InvokedProcess $process = null;

    public function __construct(
        private readonly int $port = 4100,
        private readonly string $seedPath = __DIR__.'/../Fixtures/workos-emulate.config.yaml',
    ) {}

    public static function isAvailable(): bool
    {
        // Windows is excluded deliberately, not incidentally: CI runs the full
        // suite on windows-latest, which has Node, so a presence check alone
        // would let these tests through and then burn the whole health timeout.
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        return Process::run('npx --version')->successful();
    }

    public function baseUrl(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    /**
     * The emulator takes its port from --port. It does NOT read a PORT
     * environment variable; setting one is silently ignored and the server
     * binds 4100 regardless.
     */
    public function start(): void
    {
        // --host is pinned to match what baseUrl()/isListening() probe. The
        // emulator defaults to `localhost`, which binds IPv6-only on hosts that
        // resolve it to ::1 first — every health poll would then miss.
        $command = sprintf(
            'npx --yes %s --host 127.0.0.1 --port %d --seed %s',
            self::PACKAGE,
            $this->port,
            escapeshellarg($this->seedPath),
        );

        $this->process = Process::env(['NO_UPDATE_NOTIFIER' => '1'])->start($command);

        $this->waitForHealth();
    }

    /**
     * Best-effort and deliberately non-throwing so it is safe to call from an
     * afterEach hook without masking the failure that got us there.
     */
    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        $this->process->stop();
        $this->process = null;

        // npx spawns a chain (npx -> sh -> npm exec -> node) and Symfony only
        // signals the head of it, so the node process actually holding the port
        // survives a plain stop() and breaks the next run with "port in use".
        // Match the whole chain by command line and reap it.
        Process::run(['pkill', '-f', "emulate.*--port {$this->port}"]);

        $this->waitForPortRelease();
    }

    public function isListening(): bool
    {
        $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * The budget covers a cold `npx` download of the emulator package, not just
     * server boot, so it is far wider than the sub-second warm-cache startup.
     */
    private function waitForHealth(int $timeoutSeconds = 60): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (@file_get_contents($this->baseUrl().'/health') !== false) {
                return;
            }

            // Bail out as soon as the process dies rather than polling a corpse
            // for the rest of the budget, and surface why it died.
            if ($this->process?->running() === false) {
                throw new RuntimeException(sprintf(
                    "workos/emulate exited before reporting healthy at %s/health.\nOutput: %s\nError: %s",
                    $this->baseUrl(),
                    trim($this->process->output()),
                    trim($this->process->errorOutput()),
                ));
            }

            usleep(200_000);
        }

        throw new RuntimeException(sprintf(
            "workos/emulate did not report healthy at %s/health within %ds.\nOutput: %s\nError: %s",
            $this->baseUrl(),
            $timeoutSeconds,
            trim((string) $this->process?->output()),
            trim((string) $this->process?->errorOutput()),
        ));
    }

    private function waitForPortRelease(int $timeoutSeconds = 10): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (! $this->isListening()) {
                return;
            }

            usleep(100_000);
        }
    }
}
