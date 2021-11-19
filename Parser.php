<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $login
 * @property string $password
 * @property int|null $drafter_group_id
 * @property int|null $drafter_id
 */
class Parser extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_FAIL = 'fail';
    public const STATUS_FINISH = 'finish';
    public const DOMAIN = 'domain';

    protected $fillable = [
        'domain',
        'status',
        'settings',
        'is_active',
        'time_interval',
        'login',
        'password',
        'price',
        'company_id',
        'moderator_id',
        'drafter_id',
        'drafter_group_id',
        'country_id',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'settings' => 'json',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
    ];

    public function parserErrors()
    {
        return $this->hasMany(ParserErrors::class);
    }

    public function parserProjects()
    {
        return $this->hasMany(ParserProject::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function drafter()
    {
        return $this->belongsTo(User::class, 'drafter_id', 'id');
    }

    public function drafterGroup()
    {
        return $this->belongsTo(DrafterGroup::class, 'drafter_group_id', 'id');
    }

    public function moderator()
    {
        return $this->belongsTo(User::class, 'moderator_id', 'id');
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function isActive():bool
    {
        return $this->is_active ?? false;
    }

    public function isNotActive():bool
    {
        return !$this->isActive();
    }

    public function canStart():bool
    {
        $canStartByTime = (!empty($this->started_at)) ? now()->gt($this->started_at->addMinutes($this->time_interval)) : true;
        $canStartByStatus = $this->canStartByStatus();
        $canStartByActivity = $this->isActive();

        return ($canStartByTime && $canStartByActivity && $canStartByStatus);
    }

    public function canStartByStatus()
    {
        return $this->status !== self::STATUS_IN_PROGRESS;
    }

}
