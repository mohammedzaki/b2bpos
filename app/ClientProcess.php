<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientProcess extends Model {

    use SoftDeletes;

    protected $dates = ['deleted_at'];
    protected $fillable = [
        'name',
        'client_id',
        'employee_id',
        'notes',
        'has_discount',
        'status',
        'discount_percentage',
        'discount_reason',
        'require_bill',
        'total_price'
    ];

    public function client() {
        return $this->belongsTo('App\Client');
    }

    public function items() {
        return $this->hasMany('App\ClientProcessItem', 'process_id');
    }

    public function employee() {
        return $this->hasOne('App\Employee', 'id');
    }

    public function deposits() {
        return $this->hasMany('App\DepositWithdraw', 'cbo_processes');
    }

    public function totalDeposits() {
        return $this->deposits()->where('client_id', $this->client->id)->sum('depositValue');
    }

    public function totalPriceAfterTaxes() {
        return ($this->total_price - $this->discountValue()) + $this->taxesValue();
    }
    
    public function CheckProcessMustClosed() {
        if ($this->totalPriceAfterTaxes() == $this->totalDeposits()) {
            $this->status = 'closed';
            $this->save();
            return TRUE;
        } else {
            $this->status = 'active';
            $this->save();
            return FALSE;
        }
    }

    public function discountValue() {
        if ($this->has_discount == "1") {
            return $this->total_price * ($this->discount_percentage / 100);
        }
        return 0;
    }
    
    public function taxesValue() {
        if ($this->require_bill == "1") {
            $facility = Facility::findOrFail(1);
            return ($this->total_price - $this->discountValue()) * $facility->getTaxesRate();
        }
        return 0;
    }
}
