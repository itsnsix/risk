<?php

Route::get('/', function () {
    return view('main');
});

Route::get('/import', 'InfoController@importData');
// Route::get('/territories', 'InfoController@occupiedTerritories');
Route::get('/territories', 'InfoController@territoriesWithBorders');
Route::get('/events', 'InfoController@eventIndex');
Route::get('/url', 'InfoController@getDataUrl');

// Setup routes.
// Route::post('/territories', 'InfoController@createTerritory');
// Route::put('/territories/{id}/borders', 'InfoController@setTerritoryBorders');
