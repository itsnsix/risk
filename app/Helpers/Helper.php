<?php

namespace App\Helpers;

use App\Occupation;
use App\User;

class Helper {
    // Get prepared url for data entry end point.
    public static function getDataUrl() {
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
        $color = '#' . strtoupper(dechex(rand(0x000000, 0xFFFFFF)));

        $user = User::query()->where('color', '=', $color)->exists();

        // #18424C ocean color.
        // #000000 border color.
        // #99d9EA transport color.
        if ($user || strlen($color) !== 7 || in_array($color, ['#18424C', '#000000', '#99d9EA'])) {
            // Inf. loop if more users than there are hex colors.
            return Helper::randomUniqueHexColor();
        } else {
            return $color;
        }
    }

    // Check if a data entry has been imported before.
    public static function findImportedID($id) {
        return Occupation::query()
            ->where('api_data_id', '=', $id)
            ->first();
    }
}