<?php

Route::get('/', function () {
    return view('main');
});

Route::get('/import', 'InfoController@importData');
Route::get('/territories', 'InfoController@indexedTerritories');
Route::get('/events', 'InfoController@eventIndex');
Route::get('/stats', 'InfoController@statsIndex');

// Setup routes.
// Route::post('/territories', 'InfoController@createTerritory');
// Route::put('/territories/{id}/borders', 'InfoController@setTerritoryBorders');
