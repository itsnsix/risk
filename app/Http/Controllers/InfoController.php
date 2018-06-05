<?php

namespace App\Http\Controllers;

use App\Event;
use App\Occupation;
use App\Territory;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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
            ->leftJoin('borders', function($join) {
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

    // Return all occupied territories indexed on their coordinates.
    public function occupiedTerritories() {
        return Territory::query()
            ->selectRaw("id, CONCAT(x, '-', y) as location, x, y, size")
            ->with(['occupation.user.house', 'occupation.previousOccupation.user.house'])
            ->whereHas('occupation')
            ->get()->keyBy('location');
    }

    // Find new data entries not yet imported and import them.
    public function importData() {
        $url = env('API_URL', null);
        if (!$url) {
            return response()->json('No url for data API set.', Response::HTTP_OK);
        }

        // TODO Set &from param based on highest data id previously loaded
        // TODO  and &to param on a few hundred over that, so segment batch loads to prevent timeouts.

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $result = curl_exec($ch);
        curl_close($ch);
        $entries = json_decode($result, true);

        if (!$entries) {
            return response()->json('No entries found.', Response::HTTP_OK);
        }

        foreach($entries as $entry) {
            $user = $this->findOrCreateUser($entry['userId'], 'User ' . $entry['userId']);

            if (!$this->findImportedID($entry['id'])) {
                // TODO Accept command for where to go.
                // TODO Accept command for who to attack.
                // TODO Accept command for new starting position.
                // TODO Accept command for changing color.
                // TODO Accept command for joining and leaving houses.

                $this->expandTerritory($user, $entry['id'], Carbon::now());
            }
        }

        return response()->json('OK', Response::HTTP_OK);
    }

    // Find a new unique user color (as hex).
    public function randomUniqueHexColor() {
        $color = '#' . strtoupper(dechex(rand(0x000000, 0xFFFFFF)));

        $user = User::query()->where('color', '=', $color)->first();

        // #18424C ocean color.
        // #000000 border color.
        // #99d9EA transport color.
        if ($user || strlen($color) !== 7 || in_array($color, ['#18424C', '#000000', '#99d9EA'])) {
            // Inf. loop if more users than there are hex colors.
            return $this->randomUniqueHexColor();
        } else {
            return $color;
        }
    }

    // Return user based on API user ID, create them if nonexistant.
    public function findOrCreateUser($apiUserID, $name) {
        $user = User::query()->where('api_user_id', '=', $apiUserID)->first();

        if (!$user) {
            $user = new User();
            $user->api_user_id = $apiUserID;
            $user->name = $name;
            $user->color = $this->randomUniqueHexColor();
            $user->save();
        }

        return $user;
    }

    // Expand a user's territory.
    public function expandTerritory($user, $dataID, $submittedAt) {
        $existingOccupation = Occupation::query()
            ->where('api_data_id', '=', $dataID)
            ->first();

        if (!$existingOccupation) {
            $territory = $this->findExpansion($user);

            $eventText = '<p>';
            $eventText .= "<b style='color: $user->color'>$user->name</b>";

            // Set previous occupation to inactive if this occupation overtook someone.
            if ($territory->occupation) {
                $eventText = " has taken (x: $territory->x, y: $territory->y) from " .
                    "<b style='color: $territory->occupation->user->color'>$territory->occupation->user->name</b>!";

                $previousOccupation = $territory->occupation;
                $previousOccupation->active = false;
                $previousOccupation->save();
            } else {
                $eventText .= " has taken control of (x: $territory->x, y: $territory->y)!";
            }

            // Create the occupation.
            $occupation = new Occupation();
            $occupation->user_id = $user->id;
            $occupation->active = true;
            $occupation->api_data_id = $dataID;
            $occupation->api_created_at = $submittedAt;
            $occupation->territory_id = $territory->id;
            $occupation->previous_occupation = $territory->occupation ? $territory->occupation->id : null;
            $occupation->save();

            $eventText .= '</p>';

            // Create an event for the occupation
            $event = new Event();
            $event->user_id = $user->id;
            $event->text = $eventText;
            $event->save();

            return $occupation;
        } else {
            return $existingOccupation;
        }
    }

    // Check if a data entry has been imported before.
    public function findImportedID($id) {
        return Occupation::query()
            ->where('api_data_id', '=', $id)
            ->first();
    }

    // Find a territory for the user to expand to.
    public function findExpansion($user) {
        $territories = Territory::query()
            ->whereHas('occupation', function ($query) use ($user) {
                $query->where('user_id', '=', $user->id);
            })
            ->with('borders.occupation.user', 'borderedTo.occupation.user')
            ->get();

        if (!$territories->count()) {
            return $this->findStartingSpot($user);
        } else {
            $borders = [];

            foreach ($territories as $territory) {
                $borderTerritories = $territory->borders->merge($territory->borderedTo);
                foreach ($borderTerritories as $border) {
                    if (!$border->occupation || $border->occupation->user_id !== $user->id) {
                        array_push($borders, $border);
                    }
                }
            }

            $unclaimed = Territory::query()
                ->whereDoesntHave('occupation')
                ->whereIn('id', collect($borders)->map(function($item) {return $item->id;}))
                ->get();

            // If there's free land available, take it.
            if ($unclaimed->count() > 0) {
                $unclaimed = $unclaimed->values()->all();
                return $unclaimed[array_rand($unclaimed)];
            } else {
                // No free space to expand to, take over someone's territory.
                return $borders[array_rand($borders)];
            }
        }
    }

    // Find a territory a user with no existing territories can start at.
    public function findStartingSpot($user) {
        $territory = null;

        if ($user->starting_territory) {
            $territory = Territory::query()
                ->where('id', '=', $user->starting_territory)
                ->whereDoesntHave('occupation')->first();
        }

        if (!$territory) {
            $territory = Territory::query()
                ->whereDoesntHave('occupation')->inRandomOrder()->first();
        }

        // User doesn't have a starting position set, or it's taken.
        if (!$territory) {
            $id = rand(1, 1372);

            $territory = Territory::find($id); // No more unclaimed territory.
        }

        return $territory;
    }

    public function eventIndex() {
        $events = Event::query()
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->paginate(50);

        return response()->json($events, Response::HTTP_OK);
    }

    // Set up functions, only used while setting up the map's territories and borders.

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
