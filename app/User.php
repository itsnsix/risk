<?php

namespace App;

use App\Helpers\Helper;
use finfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class User extends Model
{
    protected $hidden = ['created_at', 'updated_at'];

    // House relationship.
    public function house() {
        return $this->belongsTo(House::class);
    }

    // Change the user's territory color.
    public function changeColor($color, $submittedAt) {
        preg_match_all("/^#(?>[a-fA-F0-9]{6}){1,2}$/", $color, $matches);
        if ($matches && count($matches[0]) > 0) {
            $color = strtoupper($matches[0][0]);

            // #18424C ocean color.
            // #000000 border color.
            // #FFFFFF Unoccupied color.
            // #99d9EA transport color.
            if (strlen($color) !== 7 || in_array($color, ['#18424C', '#000000', '#99d9EA', '#FFFFFF'])) {
                Log::info('Illegal color change: ' . $this->name . ' -> ' . $color);
                return false;
            }

            if (User::query()->where('color', $color)->exists()) {
                Log::info('Duplicate color change: ' . $this->name . ' -> ' . $color);
                return false;
            }

            $eventText = "<p><b style='color: $this->color'>$this->name</b>"
                . " has changed their color to <b style='color: $color'>$color</b>.</p>";

            $this->color = $color;
            $this->save();

            $event = new Event([
                'user_id' => $this->id,
                'text' => $eventText,
                'timestamp' => $submittedAt
            ]);
            $event->save();

            return true;
        } else {
            Log::info('Failed color change: ' . $this->name . ' -> ' . $color);
            return false;
        }
    }

    // Change the users avatar.
    public function updateAvatar($userIn) {
        $avatar = $userIn['avatar'] && $userIn['avatar']['thumb'] ?  $userIn['avatar']['thumb']['url'] : null;
        if (!$avatar) {
            return false;
        }

        $image = file_get_contents($avatar);

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fileInfo->buffer($image);
        switch($mime) {
            case 'image/gif': $ext = '.gif'; break;
            case 'image/jpeg': $ext = '.jpg'; break;
            case 'image/png': $ext = '.png'; break;
            default: $ext = '.jpg';
        }

        $imagePath = '/images/avatars/' . $this->id . '-' . time();
        file_put_contents(public_path($imagePath . $ext), $image);
        $this->image = $imagePath . $ext;
        $this->save();

        return true;
    }

    // Change the user's starting spot.
    public function changeStartingPosition($territoryID, $submittedAt) {
        $territory = Territory::find($territoryID);

        if ($territory) {
            $eventText = "<p><b style='color: $this->color'>$this->name</b>"
                . " has set their starting point to <b>T$territoryID</b>.</p>";

            $this->starting_territory = $territoryID;
            $this->save();

            $event = new Event([
                'user_id' => $this->id,
                'text' => $eventText,
                'timestamp' => $submittedAt
            ]);
            $event->save();

            return true;
        } else {
            Log::info('Failed starting position change: ' . $this->name . ' -> ' . $territoryID);
            return false;
        }
    }

    // Change which house user belongs to.
    public function changeHouse($house) {
        // TODO Add user to house or leave house if $house is null.
        // TODO Delete the house if no users left in it after leaving.
        return false;
    }

    // Find starting territory for user.
    public function findStartingSpot($force = false) {
        $territory = null;

        if ($this->starting_territory) {
            // Forced start regardless of occupation.
            if ($force) {
                $territory = Territory::query()
                    ->where('id', '=', $this->starting_territory);
            } else {
                // Check if user's starting territory is unclaimed.
                $territory = Territory::query()
                    ->where('id', '=', $this->starting_territory)
                    ->whereDoesntHave('occupation')->first();
            }
        }

        // If it's occupied or no starting position, try finding a random unclaimed territory.
        if (!$territory) {
            $territory = Territory::query()
                ->whereDoesntHave('occupation')->inRandomOrder()->first();
        }

        // No more unclaimed territory, return user's starting position or a completely random territory.
        if (!$territory) {
            if ($this->starting_territory) {
                $territory = Territory::query()
                    ->where('id', '=', $this->starting_territory);
            } else {
                $lastTerritoryID = Territory::query()->orderBy('id', 'DESC')->first()->id;
                $id = rand(1, $lastTerritoryID);

                $territory = Territory::find($id);
            }
        }

        return $territory;
    }

    public function territoryClusters() {
        $userID = $this->id;

        // Get all territories
        $territories = Territory::query()
            ->whereHas('occupation', function (Builder $query) use ($userID) {
                $query->where('user_id', '=', $userID);
            })
            ->with('borders', 'borderedTo')
            ->get();
        $territoryIDs = $territories->map(function ($t) {return $t->id;});

        // Create simple ID array groups of territories borders + itself.
        $groups = [];
        foreach ($territories as $t) {
            $borders = $t->borders->map(function ($b) {return $b->id;})->concat($t->borderedTo->map(function ($b) {return $b->id;}));
            $borders->push($t->id);
            $borders = $borders->filter(function($id) use ($territoryIDs) {
                return $territoryIDs->contains($id);
            })->values();
            $groups[] = $borders->values()->all();
        }

        return Helper::clusterGroups($groups);
    }
}
