<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ExConvertRates extends Model
{
    protected $fillable=['from_currency', 'to_currency', 'rate', 'inverse_rate', 'effective_date', 'updated_at', 'created_at'];
    protected $dates=['created_at','updated_at'];
}
