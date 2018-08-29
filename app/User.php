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

    // Find starting territory for user.
    public function findStartingSpot($territoryID) {
        $territory = null;

        // TODO Don't allow user to start in land belonging to own house members.

        // Check if wanted territory is unclaimed.
        if ($territoryID) {
            $territory = Territory::query()
                ->where('id', '=', $territoryID)
                ->whereDoesntHave('occupation')
                ->first();
        }

        // If wanted territory is claimed or not provided, try finding a random unclaimed territory.
        if (!$territory) {
            $territory = Territory::query()
                ->whereDoesntHave('occupation')
                ->inRandomOrder()
                ->first();
        }

        // No more unclaimed territory, just place the user randomly on the map.
        if (!$territory) {
            $lastTerritoryID = Territory::query()
                ->orderBy('id', 'DESC')
                ->first()->id;
            $id = rand(1, $lastTerritoryID);

            $territory = Territory::find($id);
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
        $existingHouse = House::find($this->house_id);

        // User is leaving their current house.
        if ($existingHouse) {
            Helper::leaveHouse($this, $submittedAt);
        }

        if (Helper::validateHouseName($name)) {
            // Create the new house.
            $house = new House([
                'owner_id' => $this->id,
                'color' => Helper::randomUniqueHexColor(),
                'name' => $name
            ]);
            $house->save();
            $house->fresh(); // Get the ID.

            // Join the house that was just created.
            $this->house_id = $house->id;
            $this->save();

            $eventText = "<p><b style='color: $this->color'>$this->name</b>"
                . " has founded the <b style='color: $house->color'>$house->name</b> house.</p>";

            $event = new Event([
                'user_id' => $this->id,
                'text' => $eventText,
                'timestamp' => $submittedAt
            ]);
            $event->save();

            return true;
        } else return false;
    }

    // Change which house user belongs to.
    public function joinHouse($houseID, $submittedAt) {
        $existingHouse = House::find($this->house_id);

        // User is already in this house.
        if ($existingHouse && $existingHouse->id === $houseID) return false;

        // User is leaving their current house.
        if (!$houseID || $existingHouse) {
            Helper::leaveHouse($this, $submittedAt);

            // Stop if not joining another house.
            if (!$houseID) return true;
        }

        $house = House::query()
            ->where('id', $houseID)
            ->orWhere('name', 'like', '%' . $houseID  . '%')
            ->first();

        if ($house) {
            $this->house_id = $house->id;

            if($this->save()) {
                $eventText = "<p><b style='color: $this->color'>$this->name</b>"
                    . " has joined the <b style='color: $house->color'>$house->name</b> house.</p>";

                $event = new Event([
                    'user_id' => $this->id,
                    'text' => $eventText,
                    'timestamp' => $submittedAt
                ]);
                $event->save();

                return true;
            } else return false;
        }

        return false;
    }

    // Change color of user's owned house.
    public function setHouseColor($colorIn, $submittedAt) {
        $house = House::find($this->house_id);

        if (!$house || $house->owner_id !== $this->id) { // Not in a house or not the owner of their house.
            return false;
        }

        $color = Helper::validateColor($colorIn);
        if ($color) {
            $eventText = "<p><b style='color: $house->color'>$house->name</b>"
                . " has changed their color to <b style='color: $color'>$color</b>.</p>";

            $house->color = $color;
            if($house->save()) {

                $event = new Event([
                    'user_id' => $this->id,
                    'text' => $eventText,
                    'timestamp' => $submittedAt
                ]);
                $event->save();

                return true;
            } else return false;
        } else {
            Log::info('Illegal house color change: ' . $house->name . ' -> ' . $colorIn);
            return false;
        }
    }
}
