<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Middleware\AuthenticateDemoUser;

Route::get('/', function () {
    return view('workbench::demo');
})->middleware(AuthenticateDemoUser::class);
