<?php

namespace Workbench\App\Models;

use Aladler\LaravelPennantSessionAndDbDriver\Contracts\UserThatHasPreRegisterFeatures;
use Illuminate\Foundation\Auth\User as Authenticable;

class User extends Authenticable implements UserThatHasPreRegisterFeatures
{
    protected $guarded = [];
}
