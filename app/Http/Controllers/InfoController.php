<?php

namespace App\Http\Controllers;

use App\Event;
use App\Occupation;
use App\Territory;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;

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

    public function getDataUrl() {
        $url = env('API_URL', null);
        if (!$url) return null;

        $from = 1;

        $lastImport = Occupation::query()
            ->select('api_data_id')
            ->whereNotNull('api_data_id')
            ->orderBy('api_data_id', 'DESC')->first();

        if ($lastImport) {
            $from = $lastImport->api_data_id + 1;
        }

        $url .= '?from=' . $from;

        // Avoid loading huge batches of entries in one request.
        $batchSize = env('DATA_BATCH_SIZE', 200);
        if ($batchSize) {
            $url .= '&to=' . ($from + $batchSize);
        }

        return $url;
    }

    // Find new data entries not yet imported and import them.
    public function importData() {
        $url = $this->getDataUrl();
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
            return response()->json('No entries found.', Response::HTTP_OK);
        }

        foreach($entries as $entry) {
            $user = $this->findOrCreateUser($entry['user']);

            if (!$this->findImportedID($entry['id'])) {
                $direction = 'RANDOM';

                $commands = isset($entry['api_command']) ? $entry['api_command'] : null;
                if ($commands) {
                    // Structure: 'MOVE:NORTH,COLOR:#23E4DF'
                    $commands = explode(',', $commands);

                    foreach($commands as $command) {
                        $command = explode(':', $command);
                        if (count($command) === 2) { // Ignore commands not properly formatted
                            $action = strtoupper($command[0]);
                            $value = $command[1];

                            switch($action) {
                                case 'MOVE': $direction = strtoupper($value); break;
                                case 'COLOR': $user = $this->changeUserColor($user, $value, $entry['created_at']); break;
                                case 'START': $user = $this->changeUserStartingPosition($user, $value, $entry['created_at']); break;
                                case 'AVATAR': $user = $this->updateUserAvatar($user, $entry['user']); break;
                                case 'JOIN': $user = $this->changeUserHouse($user, $value); break;
                                case 'LEAVE': $user = $this->changeUserHouse($user, null); break;
                            }
                        }
                    }
                }

                $this->expandTerritory($user, $entry['id'], $entry['created_at'], $direction);
            }
        }

        return response()->json('OK', Response::HTTP_OK);
    }

    public function updateUserAvatar($user, $userIn) {
        $avatar = $userIn['avatar'] && $userIn['avatar']['thumb'] ?  $userIn['avatar']['thumb']['url'] : null;
        if (!$avatar) {
            return $user;
        }

        $imagePath = '/images/avatars/' . $user->id . '-' . time();
        $image = Image::make($avatar);
        $ext = '';
        switch($image->mime()) {
            case 'image/gif': $ext = '.gif'; break;
            case 'image/jpeg': $ext = '.jpg'; break;
            case 'image/png': $ext = '.png'; break;
            default: $ext = '.jpg';
        }

        $image->save(public_path($imagePath . $ext));
        $user->image = $imagePath . $ext;
        $user->save();

        return $user;
    }

    public function changeUserHouse($user, $house) {
        // TODO Create house if it doesn't exist and add the user to it.
        // House can be null, in which case the user leaves his current house (if any).
        return $user;
    }

    public function changeUserColor($user, $color, $submittedAt) {
        preg_match_all("/^#(?>[a-fA-F0-9]{6}){1,2}$/", $color, $matches);
        if ($matches && count($matches[0]) > 0) {
            $color = strtoupper($matches[0][0]);

            if (User::query()->where('color', $color)->exists()) {
                Log::info('Duplicate color change: ' . $user->name . ' -> ' . $color);
                return $user;
            }

            $eventText = "<p><b style='color: $user->color'>$user->name</b>"
                . " has changed their color to <b style='color: $color'>$color</b>.</p>";

            $user->color = $color;
            $user->save();

            $event = new Event();
            $event->user_id = $user->id;
            $event->text = $eventText;
            $event->timestamp = $submittedAt;
            $event->save();

            return $user;
        } else {
            Log::info('Failed color change: ' . $user->name . ' -> ' . $color);
            return $user;
        }
    }

    public function changeUserStartingPosition($user, $territoryID, $submittedAt) {
        $territory = Territory::find($territoryID);

        if ($territory) {
            $eventText = "<p><b style='color: $user->color'>$user->name</b>"
                . " has set their starting point to <b>T$territoryID</b>.</p>";

            $user->starting_territory = $territoryID;
            $user->save();

            $event = new Event();
            $event->user_id = $user->id;
            $event->text = $eventText;
            $event->timestamp = $submittedAt;
            $event->save();

            return $user;
        } else {
            Log::info('Failed starting position change: ' . $user->name . ' -> ' . $territoryID);
            return $user;
        }
    }

    // Find a new unique user color (as hex).
    public function randomUniqueHexColor() {
        $color = '#' . strtoupper(dechex(rand(0x000000, 0xFFFFFF)));

        $user = User::query()->where('color', '=', $color)->exists();

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
    public function findOrCreateUser($data) {
        $id = $data['id'];
        $name = $data['name'];
        $avatar = $data['avatar'] && $data['avatar']['thumb'] ?  $data['avatar']['thumb']['url'] : null;
        $user = User::query()->where('api_user_id', '=', $id)->first();

        if (!$user) {
            $user = new User();
            $user->api_user_id = $id;
            $user->name = $name;
            $user->color = $this->randomUniqueHexColor();
            $user->save();
        }

        // Doesn't update a user who changes his avatar.
        if ($avatar && !$user->image) {
            $imagePath = '/images/avatars/' . $id . '-' . time();
            $image = Image::make($avatar);
            $ext = '';
            switch($image->mime()) {
                case 'image/gif': $ext = '.gif'; break;
                case 'image/jpeg': $ext = '.jpg'; break;
                case 'image/png': $ext = '.png'; break;
                default: $ext = '.jpg';
            }

            $image->save(public_path($imagePath . $ext));
            $user->image = $imagePath . $ext;
            $user->save();
        }

        return $user;
    }

    // Expand a user's territory.
    public function expandTerritory($user, $dataID, $submittedAt, $direction) {
        // TODO Handle expansion directions (NULL,RANDOM,N,S,W,E,NORTH,SOUTH,WEST,EAST).

        $existingOccupation = Occupation::query()
            ->where('api_data_id', '=', $dataID)
            ->first();

        if (!$existingOccupation) {
            $territory = $this->findExpansion($user);

            $eventText = '<p>';
            $eventText .= "<b style='color: $user->color'>$user->name</b>";

            // Set previous occupation to inactive if this occupation overtook someone.
            if ($territory->occupation) {
                $lastUser = $territory->occupation->user;
                $eventText .= " has taken <b>T$territory->id</b> from " .
                    "<b style='color: $lastUser->color'>$lastUser->name</b>.";

                $previousOccupation = $territory->occupation;
                $previousOccupation->active = false;
                $previousOccupation->save();
            } else {
                $eventText .= " has taken control of <b>T$territory->id.</b>";
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
            $extra = ['territory_id' => $territory->id];
            $sourceUrl = env('ENTRY_SOURCE_URL', null);
            if ($sourceUrl) {
                $sourceUrl = str_replace('{id}', $dataID, $sourceUrl);
                $extra['source_url'] = $sourceUrl;
            }

            $event = new Event();
            $event->user_id = $user->id;
            $event->text = $eventText;
            $event->extra = json_encode($extra);
            $event->timestamp = $submittedAt;
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
}
