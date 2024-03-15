<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Ban extends Model
{


    public $fillable = [
        'admin_id', 'user_id', 'ban_type', 'internal_note', 'plubic_reason', 'expires', 'ip_address_id'
    ];

    protected $casts = [
        'expires' => 'datetime',
    ];

    const START_ON_CONNECT_BEGIN = 0;
    const BAN_BEGIN  = 0;
    const BAN48H     = 0;
    const BAN1WEEK   = 1;
    const BAN2WEEK   = 2;
    const BAN_SHADOW = 3;
    const BAN_END    = 99;
    const START_ON_CONNECT_END = 99;

    const PERMBAN    = 100;

    const START_NOW_BEGIN = 140;
    const COOLDOWN_BEGIN = 140;
    const COOLDOWN1H = 140;
    const COOLDOWN2H = 141;
    const COOLDOWN4H = 142;
    const COOLDOWN12H = 143;
    const COOLDOWN_END = 199;
    const START_NOW_END = 199;

    public function admin()
    {
        return $this->belongsTo(User::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ip()
    {
        return $this->belongsTo(IpAddress::class, 'ip_address_id');
    }

    public static function unstartedBanTime()
    {
        return Carbon::create(0, 0, 0, 0, 0, 0);
    }

    public function banHasExpired()
    {
        // Convert the timestamp to a Carbon instance
        $expiryTime = Carbon::parse($this->expires);

        // Get the current date and time
        $currentDateTime = Carbon::now();

        // Compare the current date and time with the expiry time
        if ($currentDateTime->gt($expiryTime))
        {
            return true;
        }
        return false;
    }

    public function timeTill()
    {
        if ($this->started())
        {
            $days = $this->expires->diffInDays(Carbon::now());
            $hours = $this->expires->subDays($days)->diffInHours();
            if ($days > 0)
                return "{$days} days {$hours} hours from now";
            else if ($hours > 0)
                return "{$hours} hours from now";
            else
                return $this->expires->diffForHumans();
        }
        else
            return "(Ban has not started)\n";
    }

    public function started()
    {
        if ($this->expires === null || $this->expires->eq(Ban::unstartedBanTime()))
        {
            return false;
        }
        return true;
    }

    public function checkStartBan($startBanStraightAway = false)
    {
        $banned = false;
        $cooldown = false;
        if (!$startBanStraightAway && !($this->ban_type >= Ban::START_NOW_BEGIN && $this->ban_type <= Ban::START_NOW_END))
        {
            if ($this->ban_type == Ban::PERMBAN)
                return "You are permanently banned!\n{$this->plubic_reason}";

            if ($this->ban_type <= Ban::BAN_END && $this->ban_type >= Ban::BAN_BEGIN)
                $banned = true;
            else if ($this->ban_type <= Ban::COOLDOWN_END && $this->ban_type >= Ban::COOLDOWN_BEGIN)
                $cooldown = true;
        }
        else
        {
            switch ($this->ban_type)
            {
                case Ban::BAN48H:
                    if (!$this->started())
                    {
                        $this->expires = Carbon::now()->addHours(48);
                        $this->save();
                        $banned = true;
                    }
                    else if ($this->expires->gt(Carbon::now()))
                        $banned = true;
                    break;

                case Ban::BAN1WEEK:
                    if (!$this->started())
                    {
                        $this->expires = Carbon::now()->addWeek(1);
                        $this->save();
                        $banned = true;
                    }
                    else if ($this->expires->gt(Carbon::now()))
                        $banned = true;
                    break;

                case Ban::BAN2WEEK:
                    if (!$this->started())
                    {
                        $this->expires = Carbon::now()->addWeek(2);
                        $this->save();
                        $banned = true;
                    }
                    else if ($this->expires->gt(Carbon::now()))
                        $banned = true;
                    break;

                case Ban::PERMBAN:
                case Ban::BAN_SHADOW:
                    if (!$this->started())
                    {
                        $this->expires = Carbon::create(2038, 1, 1, 0, 0, 0, 'UTC');
                        $this->save();
                    }
                    $banned = true;
                    break;

                case Ban::COOLDOWN1H:
                    if (!$this->started())
                    {
                        $this->expires = Carbon::now()->addHours(1);
                        $this->save();
                        $cooldown = true;
                    }
                    else if ($this->expires->gt(Carbon::now()))
                        $cooldown = true;
                    break;

                case Ban::COOLDOWN2H:
                    if (!$this->started())
                    {
                        $this->expires = Carbon::now()->addHours(2);
                        $this->save();
                        $cooldown = true;
                    }
                    else if ($this->expires->gt(Carbon::now()))
                        $cooldown = true;
                    break;

                case Ban::COOLDOWN4H:
                    if (!$this->started())
                    {
                        $this->expires = Carbon::now()->addHours(4);
                        $this->save();
                        $cooldown = true;
                    }
                    else if ($this->expires->gt(Carbon::now()))
                        $cooldown = true;
                    break;

                case Ban::COOLDOWN12H:
                    if (!$this->started())
                    {
                        $this->expires = Carbon::now()->addHours(12);
                        $this->save();
                        $cooldown = true;
                    }
                    else if ($this->expires->gt(Carbon::now()))
                        $cooldown = true;
                    break;

                default:
                    break;
            }
        }

        if ($banned)
        {
            return "You are BANNED!\n{$this->plubic_reason}\nYour ban will expire in {$this->timeTill()}";
        }

        if ($cooldown)
        {
            return "You are on a cool down for the next {$this->timeTill()}";
        }

        return null;
    }

    public function typeDescription()
    {
        return Ban::typeToDescription($this->ban_type);
    }


    public static function typeToDescription($ban_type)
    {
        switch ($ban_type)
        {
            case Ban::BAN48H:
                return "48 Hours";
                break;

            case Ban::BAN1WEEK:
                return "1 Week";
                break;

            case Ban::BAN2WEEK:
                return "2 Weeks";
                break;

            case Ban::PERMBAN:
                return "Permanent";
                break;

            case Ban::COOLDOWN1H:
                return "1 Hour Cooldown";
                break;

            case Ban::COOLDOWN2H:
                return "2 Hour Cooldown";
                break;

            case Ban::COOLDOWN4H:
                return "4 Hour Cooldown";
                break;

            case Ban::COOLDOWN12H:
                return "12 Hour Cooldown";
                break;

            case Ban::BAN_SHADOW:
                return "Never match anyone";

            default:
                return "nope";
                break;
        }
        return "";
    }

    public static function banStyle($ban_type)
    {
        if ($ban_type >= Ban::START_NOW_BEGIN && $ban_type <= Ban::START_NOW_END)
            return "Starts immediately";

        if ($ban_type >= Ban::START_ON_CONNECT_BEGIN && $ban_type <= Ban::START_ON_CONNECT_END)
            return "Starts next time the user tries to play";
        return "";
    }
}
