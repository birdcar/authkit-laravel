<?php

declare(strict_types=1);

it('runs install command successfully', function () {
    $this->artisan('workos:install')
        ->assertExitCode(0);
});
