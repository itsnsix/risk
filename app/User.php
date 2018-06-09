<?php

namespace App;

use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class User extends Model
{
    protected $hidden = ['created_at', 'updated_at'];

    public function house() {
        return $this->belongsTo(House::class);
    }

    public function changeColor($color, $submittedAt) {
        preg_match_all("/^#(?>[a-fA-F0-9]{6}){1,2}$/", $color, $matches);
        if ($matches && count($matches[0]) > 0) {
            $color = strtoupper($matches[0][0]);

            if (User::query()->where('color', $color)->exists()) {
                Log::info('Duplicate color change: ' . $this->name . ' -> ' . $color);
                return false;
            }

            $eventText = "<p><b style='color: $this->color'>$this->name</b>"
                . " has changed their color to <b style='color: $color'>$color</b>.</p>";

            $this->color = $color;
            $this->save();

            $event = new Event();
            $event->user_id = $this->id;
            $event->text = $eventText;
            $event->timestamp = $submittedAt;
            $event->save();

            return true;
        } else {
            Log::info('Failed color change: ' . $this->name . ' -> ' . $color);
            return false;
        }
    }

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

    public function changeStartingPosition($territoryID, $submittedAt) {
        $territory = Territory::find($territoryID);

        if ($territory) {
            $eventText = "<p><b style='color: $this->color'>$this->name</b>"
                . " has set their starting point to <b>T$territoryID</b>.</p>";

            $this->starting_territory = $territoryID;
            $this->save();

            $event = new Event();
            $event->user_id = $this->id;
            $event->text = $eventText;
            $event->timestamp = $submittedAt;
            $event->save();

            return true;
        } else {
            Log::info('Failed starting position change: ' . $this->name . ' -> ' . $territoryID);
            return false;
        }
    }

    public function changeHouse($house) {
        // TODO Create house if it doesn't exist and add the user to it.
        // House can be null, in which case the user leaves his current house (if any).
        return false;
    }
}
