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
    public function changeColor($colorIn, $submittedAt) {
        $color = Helper::validateColor($colorIn);

        if (!$color) {
            Log::info('Illegal color change: ' . $this->name . ' -> ' . $colorIn);
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
    public function changeHouse($houseID, $submittedAt) {
        $existingHouse = House::find($this->house_id);

        if (!$houseID) {
            if ($existingHouse) {
                // User is just leaving their current house.

                if (Helper::leaveHouse($this)) {
                    $eventText = "<p><b style='color: $this->color'>$this->name</b>"
                        . " has disbanded <b style='color: $existingHouse->color'>$existingHouse->name</b>.</p>";
                } else {
                    $eventText = "<p><b style='color: $this->color'>$this->name</b>"
                        . " has left <b style='color: $existingHouse->color'>$existingHouse->name</b>.</p>";
                }
            }
        } else {
            $house = House::query()
                ->where('id', $houseID)
                ->orWhere('name', $houseID)
                ->first();

            if ($this->house_id) {
                // Hopping from one house to another.
                $eventText = "<p><b style='color: $this->color'>$this->name</b>"
                    . " has left <b style='color: $existingHouse->color'>$existingHouse->name</b>"
                    . " and joined <b style='color: $house->color'>$house->name</b>.</p>";
            } else {
                // Solo player joining house.
                $eventText = "<p><b style='color: $this->color'>$this->name</b>"
                    . " has joined <b style='color: $house->color'>$house->name</b>.</p>";
            }

            $this->house_id = $house->id;
            $this->save();
        }

        $event = new Event([
            'user_id' => $this->id,
            'text' => $eventText,
            'timestamp' => $submittedAt
        ]);
        $event->save();

        return true;
    }

    // Find starting territory for user.
    public function findStartingSpot($force = false) {
        $territory = null;

        if ($this->starting_territory) {
            // Forced start regardless of occupation.
            if ($force) {
                $territory = Territory::query()
                    ->where('id', '=', $this->starting_territory)
                    ->first();
            } else {
                // Check if user's starting territory is unclaimed.
                $territory = Territory::query()
                    ->where('id', '=', $this->starting_territory)
                    ->whereDoesntHave('occupation')
                    ->first();
            }
        }

        // If it's occupied or no starting position, try finding a random unclaimed territory.
        if (!$territory) {
            $territory = Territory::query()
                ->whereDoesntHave('occupation')
                ->inRandomOrder()
                ->first();
        }

        // No more unclaimed territory, return user's starting position or a completely random territory.
        if (!$territory) {
            if ($this->starting_territory) {
                $territory = Territory::query()
                    ->where('id', '=', $this->starting_territory)
                    ->first();
            } else {
                $lastTerritoryID = Territory::query()
                    ->orderBy('id', 'DESC')
                    ->first()->id;
                $id = rand(1, $lastTerritoryID);

                $territory = Territory::find($id);
            }
        }

        return $territory;
    }

    // Find all the clusters a user controls.
    public function territoryClusters() {
        // TODO Include territories owned by house allies.

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

    // Create a new house.
    public function createHouse($name, $submittedAt) {
        // TODO Create house, leave current house if in one.
    }

    // Change color of user's owned house.
    public function setHouseColor($colorIn) {
        $house = House::find($this->house_id);

        if (!$house || $house->owner_id !== $this->id) { // Not in a house or not the owner of their house.
            return false;
        }

        $color = Helper::validateColor($colorIn);
        if ($color) {
            $house->color = $color;
            $house->save();
            return true;
        } else {
            Log::info('Illegal color change: ' . $house->name . ' -> ' . $colorIn);
            return false;
        }
    }
}
