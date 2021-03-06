<?php

namespace App;

use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * Get the configs for the exchanges.
     */
    public function exchangeConfigs()
    {
        return $this->hasMany('GTrader\UserExchangeConfig');
    }


    /**
     * Get the trades of the user.
     */
    public function trades()
    {
        return $this->hasMany('GTrader\Trade');
    }


    /**
     * Get the bots of the user.
     */
    public function bots()
    {
        return $this->hasMany('GTrader\Bot');
    }
}
