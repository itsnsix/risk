<?php

Route::get('/', function () {
    return view('main');
});

Route::get('/import', 'InfoController@importData');
Route::get('/territories', 'InfoController@indexedTerritories');
Route::get('/events', 'InfoController@eventIndex');
Route::get('/stats', 'InfoController@statsIndex');