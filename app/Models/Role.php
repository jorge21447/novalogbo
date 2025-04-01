<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
}
