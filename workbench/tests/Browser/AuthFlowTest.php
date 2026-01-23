<?php

declare(strict_types=1);

test('guest visiting dashboard is redirected to WorkOS auth', function () {
    $page = $this->visit('/dashboard');

    // Should redirect to WorkOS AuthKit
    $page->assertHostIs('*.authkit.app')
        ->assertSee('Sign in')
        ->screenshot(filename: 'guest-redirect-to-workos');
});

test('login page redirects to WorkOS', function () {
    $page = $this->visit('/auth/login');

    // Should redirect to WorkOS AuthKit
    $page->assertHostIs('*.authkit.app')
        ->screenshot(filename: 'login-page-redirect');
});

test('home page loads for guests', function () {
    $page = $this->visit('/');

    $page->assertSee('Todo App')
        ->assertSee('Sign in with WorkOS')
        ->screenshot(filename: 'home-page-guest');
});

test('todos page redirects unauthenticated users to WorkOS', function () {
    $page = $this->visit('/todos');

    $page->assertHostIs('*.authkit.app')
        ->screenshot(filename: 'todos-requires-auth');
});
