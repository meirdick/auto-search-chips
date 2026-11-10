<?php

use Illuminate\Support\Facades\Route;
use Meirdick\SearchChips\Http\SearchChipsController;

Route::middleware(config('search-chips.routes.middleware', ['web', 'auth', 'throttle:search-chips']))
    ->prefix(config('search-chips.routes.prefix', 'search-chips'))
    ->group(function () {
        Route::post('/{alias}/parse', [SearchChipsController::class, 'parse'])->name('search-chips.parse');
        Route::post('/{alias}/apply', [SearchChipsController::class, 'apply'])->name('search-chips.apply');
    });

// The script of the Blade component. Public and cacheable; it holds no data.
Route::get(config('search-chips.routes.prefix', 'search-chips').'/search-chips.js', [SearchChipsController::class, 'script'])
    ->name('search-chips.script');
