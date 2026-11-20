<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Packstub\Holler\Facades\Holler;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

Route::middleware('web')->group(function () {
    Route::get('/', function () {
        Auth::login(User::query()->firstOrCreate(['email' => 'ada@example.com'], ['name' => 'Ada Lovelace', 'password' => 'secret']));

        return view('workbench');
    });

    Route::get('second', fn () => view('workbench', ['second' => true]));
    // A server error on the replay: the "Server error" button fetches it (a failed request and an error marker).
    Route::get('boom', fn () => throw new RuntimeException('The workbench could not save https://workbench.test/orders?token=abc'));
    // Signed out on a viewer page: choose who to be, rather than becoming the visitor without a word (Assist never
    // lets someone help themselves, so an agent signed in as Ada sees an empty Help requests page).
    Route::get('login', fn () => response(<<<'HTML'
        <!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign in</title>
        <style>body{font:16px/1.5 system-ui;max-width:28rem;margin:4rem auto;padding:0 1rem}a{display:block;padding:.75rem 1rem;margin:.5rem 0;border:1px solid #ccc;border-radius:.5rem;text-decoration:none;color:inherit}small{color:#666}</style>
        </head><body><h1>Sign in to the workbench</h1>
        <a href="/">Ada Lovelace <small>the visitor: a page that records and can ask for help</small></a>
        <a href="/agent">Ana Support <small>the agent: Help requests and the recordings</small></a>
        <p><small>Use another browser or a private window for each person: they share the cookie on one address.</small></p>
        </body></html>
        HTML))->name('login');

    // Assist's agent, in another browser than the visitor on /: the Requests page as Ana Support.
    Route::get('agent', function () {
        Auth::login(User::query()->firstOrCreate(['email' => 'ana@example.com'], ['name' => 'Ana Support', 'password' => 'secret']));

        return redirect()->route('session-replay.assist.index');
    });

    // The live stage runs its own Holler client over Holler's routes, as a plain app registers them.
    if (class_exists(Holler::class)) {
        Holler::routes();
    }
});
