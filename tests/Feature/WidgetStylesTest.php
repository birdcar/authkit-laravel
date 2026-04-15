<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('registers the workos widgets css route', function () {
    $response = $this->get('/workos/widgets.css');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/css; charset=UTF-8');
    $response->assertHeader('Cache-Control', 'max-age=31536000, public');
    $response->assertHeader('Last-Modified');
});

it('serves the widgets css file content', function () {
    $response = $this->get('/workos/widgets.css');

    $content = $response->streamedContent();

    expect($content)->toContain('woswidgets-accent-color')
        ->toContain('.woswidgets-card')
        ->toContain('.woswidgets-button');
});

it('renders workosStyles directive as a link tag', function () {
    $html = Blade::render('@workosStyles');

    expect($html)->toContain('<link rel="stylesheet"')
        ->toContain('href="')
        ->toContain('/workos/widgets.css');
});

it('includes a cache-busting version hash in the link tag', function () {
    $html = Blade::render('@workosStyles');

    expect($html)->toMatch('/widgets\.css\?id=[a-f0-9]{32}/');
});
