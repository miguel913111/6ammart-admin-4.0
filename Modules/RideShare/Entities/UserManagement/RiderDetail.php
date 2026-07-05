<?php

namespace Modules\RideShare\Entities\UserManagement;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RiderDetail extends Model
{
    use HasFactory;

    protected $table = 'rider_details';

    protected $fillable = [
        'user_id',
        'is_online',
        'availability_status',
    ];

    protected $casts = [
        'is_online' => 'boolean',
    ];
}
