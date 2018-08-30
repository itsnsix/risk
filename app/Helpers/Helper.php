<?php

namespace App\Helpers;

use App\Event;
use App\House;
use App\IgnoredEntry;
use App\Occupation;
use App\Territory;
use App\User;
use Illuminate\Support\Facades\Log;

class Helper {
    // Get prepared url for data entry end point.
    public static function getDataUrl() {
        $url = env('API_URL', null);
        if (!$url) return null;

        $from = 1;

        $lastOccupation = Occupation::query()
            ->select('api_data_id')
            ->whereNotNull('api_data_id')
            ->orderBy('api_data_id', 'DESC')->first();

        $lastIgnoredEntry = IgnoredEntry::query()
            ->select('api_data_id')
            ->whereNotNull('api_data_id')
            ->orderBy('api_data_id', 'DESC')->first();

        if ($lastOccupation || $lastIgnoredEntry) {
            if ($lastOccupation && $lastIgnoredEntry) {
                $lastImport = ($lastOccupation->api_data_id > $lastIgnoredEntry->api_data_id ? $lastOccupation : $lastIgnoredEntry);
            } else if (!$lastOccupation) {
                $lastImport = $lastIgnoredEntry;
            } else if (!$lastIgnoredEntry) {
                $lastImport = $lastOccupation;
            }

            if (isset($lastImport)) {
                $from = $lastImport->api_data_id + 1;
            }
        }

        $url .= '?from=' . $from;

        // Avoid loading huge batches of entries in one request.
        $batchSize = env('DATA_BATCH_SIZE', 200);
        if ($batchSize) {
            $url .= '&to=' . ($from + $batchSize - 1);
        }

        return $url;
    }

    // Return user based on API user ID, create them if nonexistant.
    public static function findOrCreateUser($data) {
        $id = $data['id'];
        $name = $data['name'];
        $user = User::query()->where('api_user_id', '=', $id)->first();

        if (!$user) {
            $user = new User();
            $user->api_user_id = $id;
            $user->name = $name;
            $user->color = Helper::randomUniqueHexColor();
            $user->save();
        }

        if (!$user->image) { // Doesn't update a user who changes his avatar.
            $user->updateAvatar($data);
        }

        return $user;
    }

    // Find a new unique user color (as hex).
    public static function randomUniqueHexColor() {
        $color = Helper::validateColor('#' . dechex(rand(0x000000, 0xFFFFFF)));

        if (!$color) {
            // Inf. loop if more users than there are hex colors.
            return Helper::randomUniqueHexColor();
        }

        return $color;
    }

    // Check if a data entry has been imported before.
    public static function findImportedID($id) {
        $entry = Occupation::query()
            ->where('api_data_id', '=', $id)
            ->first();

        if (!$entry) {
            $entry = IgnoredEntry::query()
                ->where('api_data_id', '=', $id)
                ->first();
        }

        return $entry;
    }

    // Translate direction shorthands.
    public static function getDirection($key) {
        switch ($key) {
            case 'W':
            case 'WEST': {
                $direction = 'west'; break;
            }
            case 'N':
            case 'NORTH': {
                $direction = 'north'; break;
            }
            case 'E':
            case 'EAST': {
                $direction = 'east'; break;
            }
            case 'S':
            case 'SOUTH': {
                $direction = 'south'; break;
            }
            default: {
                $direction = null; break;
            }
        }

        return $direction;
    }

    // Group territory border groups together into connected clusters.
    public static function clusterGroups($groups) {
        $clusters = [];

        foreach ($groups as $group) {
            foreach ($clusters as $key => $cluster) {
                // Check for every number in the group.
                foreach ($group as $number) {

                    if (in_array($number, $cluster)) {
                        $clusters[$key] = array_values(array_unique(array_merge($cluster, $group)));

                        // Continue to the next group.
                        continue 3;
                    }
                }
            }

            // No matching cluster found, add the group as a new cluster.
            $clusters[] = $group;
        }

        // If nothing happened everything has been merged.
        if ($groups === $clusters) {
            return $clusters;
        } else {
            return Helper::clusterGroups($clusters);
        }
    }

    // Remove the user from their house and disband it if they are the owner.
    public static function leaveHouse($user, $submittedAt) {
         $house = House::find($user->house_id);
         if (!$house) return false;

        // If the user is the owner, disband the house.
        if ($house->owner_id === $user->id) {
            // Remove all the members.
            User::query()
                ->where('house_id', $house->id)
                ->update([
                    'house_id' => null
                ]);

            // Delete the house.
            $house->delete();

            $eventText = "<p><b style='color: $user->color'>$user->name</b>"
                . " has disbanded the <b style='color: $house->color'>$house->name</b> house.</p>";

        } else {
            // If not, just leave the house.
            $user->house_id = null;
            $user->save();

            // TODO Transfer user's territories to house owner.

            $eventText = "<p><b style='color: $user->color'>$user->name</b>"
                . " has left the <b style='color: $house->color'>$house->name</b> house.</p>";
        }

        $event = new Event([
            'user_id' => $user->id,
            'text' => $eventText,
            'timestamp' => $submittedAt
        ]);
        $event->save();

        return true;
    }

    // Validate a string as a valid unique hex color.
    public static function validateColor($colorIn) {
        // #18424C ocean color.
        // #000000 border color.
        // #FFFFFF Unoccupied color.
        // #99d9EA transport color.

        preg_match_all("/^#(?>[a-fA-F0-9]{6}){1,2}$/", $colorIn, $matches);
        if ($matches && count($matches[0]) > 0) {
            $color = strtoupper($matches[0][0]);

            $duplicateUserColor = User::query()->where('color', $color)->exists();
            $duplicateHouseColor = House::query()->where('color', $color)->exists();

            if ($duplicateUserColor
                || $duplicateHouseColor
                || substr($color,0,1) !== '#'
                || strlen($color) !== 7
                || in_array($color, ['#18424C', '#000000', '#99d9EA', '#FFFFFF'])
            ) {
                return null;
            }

            return $color;
        }

        return null;
    }

    // Validate a new house name.
    public static function validateHouseName($name) {
        // Check length.
        $len = strlen($name);
        if ($len === 0 || $len > env('MAX_HOUSE_NAME_LENGTH', 24)) {
            Log::info('Illegal house name: ' . $name);
            return false;
        }

        // Check if another house already exists with this name.
        $duplicateName = House::query()->where('name', $name)->first();
        if ($duplicateName) {
            Log::info('Duplicate house name: ' . $name);
            return false;
        }

        return true;
    }

    // Return all territory ID's currently controlled by a house.
    public static function findHouseterritoryIDs($houseID) {
        return Territory::query()
            ->select('territories.id')
            ->join('occupations', 'occupations.territory_id', '=', 'territories.id')
            ->join('users', 'users.id', '=', 'occupations.user_id')
            ->where([
                ['occupations.active', true],
                ['users.house_id', $houseID]
            ])
            ->orderBy('territories.id')
            ->pluck('id')
            ->toArray();
    }
}