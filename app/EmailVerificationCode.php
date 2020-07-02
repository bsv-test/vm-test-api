<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use App\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EmailVerificationCode extends Model
{
    public const STATE_CREATED = 'created';
    public const STATE_SENT = 'sent';
    public const STATE_ACTIVATED = 'activated';
    public const STATE_INVALIDATED = 'invalidated';
    public const STATE_EXPIRED = 'expired';

    public const CODE_LENGTH = 4;
    public const CODE_LIFE_TIME_MINUTES = 5;
    public const MAX_ATTEMPTS = 3;
    public const LIMIT_USER_REQUESTS_PER_HOUR = 5;
    public const LIMIT_USER_REQUESTS_PER_5_MINUTES = 1;

    public static $pendingStates = [self::STATE_CREATED, self::STATE_SENT];

    protected $attributes = [
        'state' => self::STATE_CREATED,
    ];

    public static function createForUser(User $user)
    {
        $selfObject = new self();
        $selfObject->code = $selfObject->generateCode();
        $selfObject->user()->associate($user);
        $selfObject->save();
        $selfObject->invalidatePreviousCodes();
        return $selfObject;
    }

    public static function check($email, $requestedCode)
    {
        $user = User::where('email', $email)->first();
        if (!$user) {
            return false;
        }

        $code = self::where('user_id', $user->id)
            ->whereIn('state', self::$pendingStates)
            ->latest('created_at')
            ->first();
        if (!$code) {
            return false;
        }

        if (Carbon::now()->diffInMinutes($code->created_at) > self::CODE_LIFE_TIME_MINUTES) {
            $code->expire();
            return false;
        }

        if ($code->code !== $requestedCode) {
            $code->handleAttempt();
            return false;
        }

        $code->activate();
        return true;
    }

    public static function canUserGetNewCode($user)
    {
        $codesHourCount = EmailVerificationCode::where('user_id', $user->id)
            ->whereIn('state', self::$pendingStates)
            ->where('created_at', '>', Carbon::now()->subHour())
            ->count();
        $codes5MinutesCount = EmailVerificationCode::where('user_id', $user->id)
            ->whereIn('state', self::$pendingStates)
            ->where('created_at', '>', Carbon::now()->subMinutes(5))
            ->count();
        if ($codesHourCount >= self::LIMIT_USER_REQUESTS_PER_HOUR) {
            return false;
        }

        if ($codes5MinutesCount >= self::LIMIT_USER_REQUESTS_PER_5_MINUTES) {
            return false;
        }

        return true;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function activate()
    {
        DB::transaction(function () {
            $this->state = self::STATE_ACTIVATED;
            $this->save();

            $user = $this->user()->getResults();
            $user->email_verified_at = Carbon::now();
            $user->save();
        });
    }

    public function expire()
    {
        $this->setState(self::STATE_EXPIRED);
    }

    public function invalidate()
    {
        $this->setState(self::STATE_INVALIDATED);
    }

    public function markAsSent()
    {
        $this->setState(self::STATE_SENT);
    }

    public function handleAttempt()
    {
        $this->attempts++;
        $this->save();
        if ($this->attempts >= self::MAX_ATTEMPTS) {
            $this->invalidate();
        }
    }

    public function invalidatePreviousCodes()
    {
        $userId = $this->user()->getResults()->id;
        return $this->where('user_id', $userId)
            ->whereIn('state', self::$pendingStates)
            ->where('id', '!=', $this->id)
            ->update(['state' => self::STATE_INVALIDATED]);
    }

    private function generateCode()
    {
        $maxNumber = pow(10, self::CODE_LENGTH) - 1;
        $code = rand(1, $maxNumber);
        $codePadded = str_pad($code, self::CODE_LENGTH, '0', STR_PAD_LEFT);
        return $codePadded;
    }

    private function setState($state)
    {
        $this->state = $state;
        $this->save();
    }
}
