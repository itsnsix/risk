<?php

namespace App\Http\Controllers;

use App\Event;
use App\Helpers\Helper;
use App\IgnoredEntry;
use App\Occupation;
use App\Territory;
use App\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Query\JoinClause;

class InfoController extends Controller
{
    // Return all territories.
    public function index() {
        return response()->json(Territory::all(), Response::HTTP_OK);
    }

    // Return all territories indexed on their coordinates.
    public function indexedTerritories() {
        return Territory::query()
            ->selectRaw("id, CONCAT(x, '-', y) as location, x, y, size")
            ->with(['occupation.user.house', 'occupation.previousOccupation.user.house'])
            ->get()->keyBy('location');
    }

    // Return all occupied territories indexed on their coordinates.
    public function occupiedTerritories() {
        return Territory::query()
            ->selectRaw("id, CONCAT(x, '-', y) as location, x, y, size")
            ->with(['occupation.user.house', 'occupation.previousOccupation.user.house'])
            ->whereHas('occupation')
            ->get()->keyBy('location');
    }

    // Return all territories with all their bordering territories indexed on their coordinates.
    public function territoriesWithBorders() {
        // Heavy function, primarily used to quality check the map's borders.

        $ts = Territory::query()
            ->select('territories.*',
                DB::raw("CONCAT(territories.x, '-', territories.y) as location"),
                DB::raw('GROUP_CONCAT(IF(borders.territory_id = territories.id, borders.bordering_id, borders.territory_id)) AS border_ids')
            )
            ->leftJoin('borders', function (JoinClause $join) {
                $join->on('borders.territory_id', '=', 'territories.id');
                $join->orOn('borders.bordering_id', '=', 'territories.id');
            })
            ->with(['occupation.user.house'])
            ->groupBy('territories.id')
            ->get();

        $ts->each(function ($t) {
            $t->borders = Territory::query()->whereIn('id', explode(',', $t->border_ids))->get();
        });

        return $ts->keyBy('location');
    }

    // General stats for the site.
    public function statsIndex() {
        $totalTerritoryCount = Territory::query()->count();

        $firstOccupation = Occupation::query()
            ->selectRaw('DATEDIFF(DATE(NOW()), DATE(api_created_at)) as days_since')
            ->orderBy('api_created_at', 'ASC')->first();
        if ($firstOccupation) {
            $day = $firstOccupation->days_since + 1;
        } else {
            $day = 0;
        }

        $highscore = User::query()
            ->selectRaw('users.*, COUNT(user_id) as occupations')
            ->join('occupations', 'occupations.user_id', '=', 'users.id')
            ->groupBy('user_id')
            ->where('active', true)
            ->orderBy('occupations', 'DESC')
            ->limit(3)
            ->get();

        return response()->json([
            'day' => $day,
            'territory_count' => $totalTerritoryCount,
            'highscore' => $highscore
        ], Response::HTTP_OK);
    }

    // Find new data entries not yet imported and import them.
    public function importData() {
        $url = Helper::getDataUrl();
        if (!$url) {
            return response()->json('No url for data API set.', Response::HTTP_OK);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $result = curl_exec($ch);
        curl_close($ch);
        $entries = json_decode($result, true);

        if (!$entries) {
            return response()->json(['result' => 'No new entries found.'], Response::HTTP_OK);
        }

        $importCount = 0;

        foreach($entries as $entry) {
            $user = Helper::findOrCreateUser($entry['user']);

            if (!Helper::findImportedID($entry['id'])) {
                $direction = 'RANDOM';
                $territoryID = null;
                $startPos = null;

                $commands = isset($entry['api_command']) ? $entry['api_command'] : null;

                if ($commands) {
                    Log::info('[COMMAND] ' . $user->name . ': ' . $commands);

                    // Structure: 'MOVE:NORTH,COLOR:#23E4DF,UPDATE_AVATAR'
                    $commands = explode(',', $commands);

                    foreach($commands as $command) {
                        $command = explode(':', $command);
                        if (count($command) <= 2) { // Ignore commands not properly formatted
                            $action = strtoupper($command[0]);
                            $value = count($command) > 1 ? $command[1] : null;

                            switch($action) {
                                // Expansion commands
                                case 'MOVE': $direction = strtoupper($value); break;
                                case 'TAKE': $territoryID = $value; break;

                                // User changes
                                case 'COLOR': $user->changeColor($value, $entry['created_at']); break;
                                case 'START':
                                    $startPos = $value;
                                    break;
                                case 'UPDATE_AVATAR': $user->updateAvatar($entry['user']); break;

                                // House commands
                                /* Disabled until house functionality is finished.
                                case 'CREATE_HOUSE': $user->createHouse($value, $entry['created_at']); break;
                                case 'HOUSE_COLOR': $user->setHouseColor($value, $entry['created_at']); break;
                                case 'JOIN_HOUSE': $user->joinHouse($value, $entry['created_at']); break;
                                case 'LEAVE_HOUSE': $user->joinHouse(null, $entry['created_at']); break;
                                */
                            }
                        }
                    }
                }

                $this->expandTerritory($user, $entry['id'], $entry['created_at'], $direction, $territoryID, $startPos);
                $this->cleanupClusters($entry['created_at']); // Severe performance hit
                $importCount++;
            }
        }

        return response()->json(['result' => 'Imported ' . $importCount .
            ($importCount === 1 ? ' new entry!' : ' new entries!')],
            Response::HTTP_OK);
    }

    // Expand a user's territory.
    public function expandTerritory(User $user, $dataID, $submittedAt, $direction, $territoryID, $startPos) {
        $expansion = $this->findExpansion($user, $direction, $territoryID, $submittedAt, $startPos);
        $territory = $expansion['territory'];
        $expansion = $expansion['status']; // START || EXPAND || EXHAUSTED

        $eventText = "<p>";
        $eventText .= "<b style='color: $user->color'>$user->name</b>";

        if ($expansion === 'EXHAUSTED') {
            $eventText .= " tries to expand, but their army is <b>exhausted</b>.</p>";

            $sourceUrl = env('ENTRY_SOURCE_URL', null);
            if ($sourceUrl) {
                $sourceUrl = str_replace('{id}', $dataID, $sourceUrl);
                $extra = ['source_url' => $sourceUrl];
            }

            $event = new Event([
                'user_id' => $user->id,
                'text' => $eventText,
                'extra' => (isset($extra) ? json_encode($extra): null),
                'timestamp' => $submittedAt
            ]);
            $event->save();

            $ignoredEntry = new IgnoredEntry([
                'user_id' => $user->id,
                'api_data_id' => $dataID,
                'api_created_at' => $submittedAt
            ]);
            $ignoredEntry->save();

            return $ignoredEntry;
        }

        // Set previous occupation to inactive if this occupation overtook someone.
        if ($territory->occupation) {
            $lastUser = $territory->occupation->user;
            if ($expansion === 'START') {
                $eventText .= " has appeared in <b>T$territory->id</b> and taken it from ";
            } else {
                $d = Helper::getDirection($direction);

                if ($d) {
                    $eventText .= " has moved $d and taken <b>T$territory->id</b> from ";
                } else {
                    if ($territoryID) {
                        $eventText .= " attacked and has taken <b>T$territory->id</b> from ";
                    } else {
                        $eventText .= " has taken <b>T$territory->id</b> from ";
                    }
                }
            }
            $eventText .= "<b style='color: $lastUser->color'>$lastUser->name</b>.";

            $previousOccupation = $territory->occupation;
            $previousOccupation->active = false;
            $previousOccupation->save();
        } else {
            if ($expansion === 'START') {
                $eventText .= " has appeared in <b>T$territory->id</b>.";
            } else {
                $d = Helper::getDirection($direction);

                if ($d) {
                    $eventText .= " has moved $d and taken control of <b>T$territory->id</b>.";
                } else {
                    $eventText .= " has taken control of <b>T$territory->id</b>.";
                }
            }
        }

        // Create the occupation.
        $occupation = new Occupation([
            'user_id' => $user->id,
            'active' => true,
            'api_data_id' => $dataID,
            'api_created_at' => $submittedAt,
            'territory_id' => $territory->id,
            'previous_occupation' => ($territory->occupation ? $territory->occupation->id : null)
        ]);
        $occupation->save();

        $eventText .= '</p>';

        // Create an event for the occupation
        $extra = ['territory_id' => $territory->id];
        $sourceUrl = env('ENTRY_SOURCE_URL', null);
        if ($sourceUrl) {
            $sourceUrl = str_replace('{id}', $dataID, $sourceUrl);
            $extra['source_url'] = $sourceUrl;
        }

        $event = new Event([
            'user_id' => $user->id,
            'text' => $eventText,
            'extra' => json_encode($extra),
            'timestamp' => $submittedAt
        ]);
        $event->save();

        return $occupation;
    }

    // Find a territory for the user to expand to.
    public function findExpansion(User $user, $direction, $territoryID, $submittedAt, $startPos) {
        if ($user->house_id) {
            // Treat territories owned by your own house as your own.
            $territories = Territory::query()
                ->select('territories.id')
                ->join('occupations', 'occupations.territory_id', '=', 'territories.id')
                ->join('users', 'users.id', '=', 'occupations.user_id')
                ->whereHas('occupation', function (Builder $query) use ($user) {
                    $query->where('house_id', '=', $user->house_id);
                })
                ->where('occupations.active', '=', true)
                ->with('borders.occupation.user', 'borderedTo.occupation.user')
                ->get();
        } else {
            $territories = Territory::query()
                ->whereHas('occupation', function (Builder $query) use ($user) {
                    $query->where('user_id', '=', $user->id);
                })
                ->with('borders.occupation.user', 'borderedTo.occupation.user')
                ->get();
        }

        // User or house's first post
        if (!$territories->count()) {
            return ['territory' => $user->findStartingSpot($startPos), 'status' => 'START'];
        } else {
            // Get number of entries already submitted on the same day as this entry.
            $expansionsToday = Occupation::query()
                ->where('user_id', '=', $user->id)
                ->whereRaw("DATE(api_created_at) = DATE(\"$submittedAt\")")
                ->count();

            if ($expansionsToday >= env('EXPAND_LIMIT', 3)) {
                return ['territory' => null, 'status' => 'EXHAUSTED'];
            }

            $borders = [];

            // Merge territory's borders and borderedTo to find available territories for expansion.
            foreach ($territories as $territory) {
                $borderTerritories = $territory->borders->merge($territory->borderedTo);
                foreach ($borderTerritories as $border) {
                    if ($border->occupation) {
                        // Careful to keep these defined in the flow they're used.
                        $checker = $user->house_id ? $border->occupation->user->house_id : $border->occupation->user->id;
                        $checkAgainst = $user->house_id ? $user->house_id : $user->id;
                    }

                    // If you or your house don't currently occupy this border
                    if (!$border->occupation || $checker !== $checkAgainst) {
                        if ($territoryID && $territoryID == $border->id) { // Take specific wanted territory by ID.
                            return ['territory' => $border, 'status' => 'EXPAND'];
                        }

                        // Put territory into array of available borders.
                        array_push($borders, $border);
                    }
                }
            }

            switch ($direction) {
                // Directional expansion do not care if the territory is occupied or not already.
                case 'W':
                case 'WEST': {
                    $bestTerritory = array_reduce ($borders, function($a, $b){
                        return $a ? ($a->x < $b->x ? $a : $b) : $b;
                    });
                    return ['territory' => $bestTerritory, 'status' => 'EXPAND'];
                    break;
                }
                case 'N':
                case 'NORTH': {
                    $bestTerritory = array_reduce ($borders, function($a, $b){
                        return $a ? ($a->y < $b->y ? $a : $b) : $b;
                    });
                    return ['territory' => $bestTerritory, 'status' => 'EXPAND'];
                    break;
                }
                case 'E':
                case 'EAST': {
                    $bestTerritory = array_reduce ($borders, function($a, $b){
                        return $a ? ($a->x > $b->x ? $a : $b) : $b;
                    });
                    return ['territory' => $bestTerritory, 'status' => 'EXPAND'];
                    break;
                }
                case 'S':
                case 'SOUTH': {
                    $bestTerritory = array_reduce ($borders, function($a, $b){
                        return $a ? ($a->y > $b->y ? $a : $b) : $b;
                    });
                    return ['territory' => $bestTerritory, 'status' => 'EXPAND'];
                    break;
                }
                default: { // Expand randomly to a border. Prefers unoccupied territories.
                    $unclaimed = Territory::query()
                        ->whereDoesntHave('occupation')
                        ->whereIn('id', collect($borders)->map(function ($item) { return $item->id; }))
                        ->get();

                    // If there's free land available, take it.
                    if ($unclaimed->count() > 0) {
                        $unclaimed = $unclaimed->values()->all();
                        return ['territory' => $unclaimed[array_rand($unclaimed)], 'status' => 'EXPAND'];
                    } else {
                        // No free space to expand to, take over someone's territory.
                        return ['territory' => $borders[array_rand($borders)], 'status' => 'EXPAND'];
                    }
                    break;
                }
            }
        }
    }

    // Paginated list of all events available.
    public function eventIndex() {
        $events = Event::query()
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->paginate(50);

        return response()->json($events, Response::HTTP_OK);
    }

    // Create a new territory.
    public function createTerritory(Request $request) {
        $this->validate($request, [
            'x' => 'required|integer',
            'y' => 'required|integer',
            'size' => 'required|integer'
        ]);

        $t = new Territory($request->only(['x', 'y', 'size']));
        if ($t->save()) {
            return response()->json($t, Response::HTTP_OK);
        } else {
            return response()->json(['error' => 'Whoops! Something went wrong.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // Set which territories a territory borders to.
    public function setTerritoryBorders($id, Request $request) {
        $territory = Territory::find($id);

        if (!$territory) {
            return response()->json(['error' => 'Whoops! This territory doesn\' exist.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->validate($request, [
            'border_ids' => 'required|array',
            'border_ids.*' => 'required|integer|exists:territories,id'
        ]);

        $syncResult = $territory->borders()->sync($request->input('border_ids'));

        return response()->json(['result' => $syncResult], $syncResult ? Response::HTTP_OK : Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    // Cut off and unclaim all un-linked territory clusters.
    public function cleanupClusters($time) {
        $users = User::all();
        $total = collect();

        foreach ($users as $user) {
            $updated = 0;

            $clusters = $user->territoryClusters();

            $biggestCluster = 0;
            foreach ($clusters as $key => $cluster) {
                if (count($cluster) > count($clusters[$biggestCluster])) {
                    // Save biggest by key instead of size in case several clusters of same size exist.
                    $biggestCluster = $key;
                }
            }

            // TODO Logs wrong count per user when cut off by someone joining house.

            foreach ($clusters as $key => $cluster) {
                if ($key !== $biggestCluster) {
                    $updated += Occupation::query()
                        ->whereIn('territory_id', $cluster)
                        ->where([['user_id', '=', $user->id], ['active', '=', true]])
                        ->update(['active' => false]);
                }
            }

            if ($updated) {
                $eventText = "<p><b style='color: $user->color'>$user->name</b>";
                $eventText .= " has been <b>cut off</b> from $updated of their territories.</p>";

                $event = new Event([
                    'user_id' => $user->id,
                    'text' => $eventText,
                    'timestamp' => $time
                ]);
                $event->save();

                $total->put($user->name, $updated);
            }
        }

        return response()->json(['result' => $total], Response::HTTP_OK);
    }
}
