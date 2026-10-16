<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

Route::middleware('web')->group(function () {
    Route::get('/', function () {
        Auth::login(User::query()->firstOrCreate(['email' => 'ada@example.com'], ['name' => 'Ada Lovelace', 'password' => 'secret']));

        return view('workbench');
    });

    Route::get('second', fn () => view('workbench', ['second' => true]));
    // A server error on the replay: the "Server error" button fetches it (a failed request and an error marker).
    Route::get('boom', fn () => throw new RuntimeException('The workbench could not save https://workbench.test/orders?token=abc'));
    Route::get('login', fn () => redirect('/'))->name('login');
});
