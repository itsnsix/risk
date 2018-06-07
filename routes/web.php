<?php

Route::get('/', function () {
    return view('main');
});

Route::get('/import', 'InfoController@importData');
Route::get('/territories', 'InfoController@occupiedTerritories');
Route::get('/events', 'InfoController@eventIndex');

// Setup routes.
// Route::post('/territories', 'InfoController@createTerritory');
// Route::put('/territories/{id}/borders', 'InfoController@setTerritoryBorders');
