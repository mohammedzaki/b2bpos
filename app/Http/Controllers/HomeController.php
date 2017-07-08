<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller {

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct() {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        return view('home');
    }

    public function fixProcess() {
        /*
          'name',
          'client_id',
          'employee_id',
          'notes',
          'has_discount',
          'status',
          'discount_percentage',
          'discount_value',
          'discount_reason',
          'require_invoice',
          'has_source_discount',
          'source_discount_percentage',
          'source_discount_value',
          'invoice_billed',
          'total_price',
          'total_price_taxes',
          'taxes_value',
          'invoice_id'
         */
        $cProcess = \App\Models\ClientProcess::all();
        foreach ($cProcess as $cp) {
            if ($cp->require_invoice) {
                $taxes_value = round($cp->total_price * 0.13, 3);
                $cp->taxes_value = $taxes_value;
                $cp->total_price_taxes = round(($cp->total_price + $taxes_value) - ($cp->source_discount_value + $cp->discount_value), 3);
                $cp->save();
                echo "client: {$cp->id} <br/>";
            }
        }

        $sProcess = \App\Models\SupplierProcess::all();
        foreach ($sProcess as $sp) {
            if ($sp->require_invoice) {
                $taxes_value = round($sp->total_price * 0.13, 3);
                $sp->taxes_value = $taxes_value;
                $sp->total_price_taxes = round(($sp->total_price + $taxes_value) - ($sp->source_discount_value + $sp->discount_value), 3);
                $sp->save();
                echo "supplier: {$sp->id} <br/>";
            }
        }
        echo 'done';
    }

}
