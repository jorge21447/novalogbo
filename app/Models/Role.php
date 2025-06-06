<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'description',
    ];

    protected $hidden = [

    ];

    protected $casts = [

    ];

    public $timestamps = true;

    public function users():HasMany
    {
        return $this->hasMany(User::class);
    }
    public function customers():HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
