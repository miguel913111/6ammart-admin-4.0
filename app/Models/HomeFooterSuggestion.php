<?php

namespace App\Models;

use App\CentralLogics\Helpers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HomeFooterSuggestion extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'status' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::saved(function () {
            Helpers::deleteCacheData('home_footer_suggestions');
        });

        static::deleted(function () {
            Helpers::deleteCacheData('home_footer_suggestions');
        });
    }
}
