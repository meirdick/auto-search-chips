<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'demo', ['stack' => 'livewire']);
Route::view('/blade', 'demo', ['stack' => 'blade']);
