<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/docs', '/docs/api');

Route::get('/docs/api', function () {
    return response()->file(base_path('docs/swagger.html'));
})->name('docs.api');

Route::get('/docs/openapi.yaml', function () {
    return response()->file(base_path('docs/openapi.yaml'), [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
})->name('docs.openapi');
