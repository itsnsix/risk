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
            ->leftJoin('borders', function(JoinClause $join) {
                $join->on('borders.territory_id', '=', 'territories.id');
                $join->orOn('borders.bordering_id', '=', 'territories.id');
            })
            ->with(['occupation.user.house'])
            ->groupBy('territories.id')
            ->get();

        $ts->each(function($t) {
            $t->borders = Territory::query()->whereIn('id', explode(',', $t->border_ids))->get();
        });

        return $ts->keyBy('location');
    }

    // General stats for the site.
    public function statsIndex() {
        $totalTerritoryCount = Territory::query()->count();
        $totalOccupationCount = Occupation::query()->where('active', true)->count();
        $occupation = (float) number_format($totalOccupationCount / $totalTerritoryCount * 100, 1);

        $firstOccupation = Occupation::query()
            ->selectRaw('DATEDIFF(DATE(NOW()), DATE(api_created_at)) as days_since')
            ->orderBy('api_created_at', 'ASC')->first();
        if ($firstOccupation) {
            $day = $firstOccupation->days_since + 1;
        } else {
            $day = 0;
        }

        $angriest = User::query()
            ->selectRaw('users.*, COUNT(user_id) as take_overs')
            ->join('occupations', 'occupations.user_id', '=', 'users.id')
            ->whereNotNull('previous_occupation')
            ->groupBy('user_id')
            ->orderBy('take_overs', 'DESC')
            ->first();

        $biggest = User::query()
            ->selectRaw('users.*, SUM(size) as total_size')
            ->join('occupations', 'occupations.user_id', '=', 'users.id')
            ->join('territories', 'territories.id', '=', 'occupations.territory_id')
            ->groupBy('user_id')
            ->orderBy('total_size', 'DESC')
            ->first();

        $highestCount = User::query()
            ->selectRaw('users.*, COUNT(user_id) as occupations')
            ->join('occupations', 'occupations.user_id', '=', 'users.id')
            ->groupBy('user_id')
            ->where('active', true)
            ->orderBy('occupations', 'DESC')
            ->first();

        return response()->json([
            'occupied_percentage' => $occupation,
            'day' => $day,
            'angriest' => $angriest,
            'biggest' => $biggest,
            'highest_count' => $highestCount
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
                $forceStart = false;

                $commands = isset($entry['api_command']) ? $entry['api_command'] : null;
                if ($commands) {
                    Log::info('[COMMAND] ' . $user->name . ': ' . $commands);

                    // Structure: 'MOVE:NORTH,COLOR:#23E4DF'
                    $commands = explode(',', $commands);

                    foreach($commands as $command) {
                        $command = explode(':', $command);
                        if (count($command) === 2) { // Ignore commands not properly formatted
                            $action = strtoupper($command[0]);
                            $value = $command[1];

                            switch($action) {
                                // Expansion commands
                                case 'MOVE': $direction = strtoupper($value); break;
                                case 'TAKE': $territoryID = $value; break;

                                // User changes
                                case 'COLOR': $user->changeColor($value, $entry['created_at']); break;
                                case 'START':
                                    $user->changeStartingPosition($value, $entry['created_at']);
                                    $forceStart = true;
                                    break;
                                case 'AVATAR': $user->updateAvatar($entry['user']); break;

                                // House commands
                                case 'CREATE_HOUSE': break; // TODO Create a new house.
                                case 'JOIN_HOUSE': $user->changeHouse($value); break;
                                case 'LEAVE_HOUSE': $user->changeHouse(null); break;
                            }
                        }
                    }
                }

                $this->expandTerritory($user, $entry['id'], $entry['created_at'], $direction, $territoryID, $forceStart);
                $importCount++;
            }
        }

        return response()->json(['result' => 'Imported ' . $importCount .
            ($importCount === 1 ? ' new entry!' : ' new entries!')],
            Response::HTTP_OK);
    }

    // Expand a user's territory.
    public function expandTerritory(User $user, $dataID, $submittedAt, $direction, $territoryID, $forceStart) {
        $expansion = $this->findExpansion($user, $direction, $territoryID, $submittedAt, $forceStart);
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
    public function findExpansion(User $user, $direction, $territoryID, $submittedAt, $forceStart) {
        // TODO Don't expand into territories owned by your own house, treat them as your own.

        $territories = Territory::query()
            ->whereHas('occupation', function (Builder $query) use ($user) {
                $query->where('user_id', '=', $user->id);
            })
            ->with('borders.occupation.user', 'borderedTo.occupation.user')
            ->get();

        // User's first post
        if (!$territories->count()) {
            return ['territory' => $user->findStartingSpot($forceStart), 'status' => 'START'];
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
                    if (!$border->occupation || $border->occupation->user_id !== $user->id) {
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
                    $bestTerritory = array_reduce($borders, function($a, $b){
                        return $a ? ($a->x < $b->x ? $a : $b) : $b;
                    });
                    return ['territory' => $bestTerritory, 'status' => 'EXPAND'];
                    break;
                }
                case 'N':
                case 'NORTH': {
                    $bestTerritory = array_reduce($borders, function($a, $b){
                        return $a ? ($a->y < $b->y ? $a : $b) : $b;
                    });
                    return ['territory' => $bestTerritory, 'status' => 'EXPAND'];
                    break;
                }
                case 'E':
                case 'EAST': {
                    $bestTerritory = array_reduce($borders, function($a, $b){
                        return $a ? ($a->x > $b->x ? $a : $b) : $b;
                    });
                    return ['territory' => $bestTerritory, 'status' => 'EXPAND'];
                    break;
                }
                case 'S':
                case 'SOUTH': {
                    $bestTerritory = array_reduce($borders, function($a, $b){
                        return $a ? ($a->y > $b->y ? $a : $b) : $b;
                    });
                    return ['territory' => $bestTerritory, 'status' => 'EXPAND'];
                    break;
                }
                default: { // Expand randomly to a border. Prefers unoccupied territories.
                    $unclaimed = Territory::query()
                        ->whereDoesntHave('occupation')
                        ->whereIn('id', collect($borders)->map(function($item) {return $item->id;}))
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

    // Create a new territory
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
}
