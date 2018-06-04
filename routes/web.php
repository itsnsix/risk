<?php

Route::get('/', function () {
    return view('main');
});

Route::get('/import', 'InfoController@importEntries');
// Route::get('/territories', 'InfoController@occupiedTerritories');
Route::get('/territories', 'InfoController@territoriesWithBorders');

// Setup routes.
// Route::post('/territories', 'InfoController@createTerritory');
// Route::put('/territories/{id}/borders', 'InfoController@setTerritoryBorders');
