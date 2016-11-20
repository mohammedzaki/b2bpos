<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Facility;
use App\User;
use Validator;

class FacilityController extends Controller
{
    /**
	 * Create a new controller instance.
	 *
	 * @return void
	 */
	public function __construct()
	{
	    $this->middleware('auth');
        $this->middleware('ability:admin,facility-info');
	}

    protected function validator(array $data)
    {
        $validator = Validator::make($data, [
            'name' => 'required|max:255',
            'manager' => 'max:255',
            'type' => 'required|in:individual,joint,partnership,limited_partnership,stock',
            'tax_file' => 'numeric',
            'tax_card' => 'numeric',
            'trade_record' => 'numeric',
            'sales_tax' => 'numeric|max:60|min:0',
            'opening_amount' => 'numeric',
            //'country_sales_tax' => 'numeric',
            'logo' => 'image',
            'email' => 'email',
        ]);

        $validator->setAttributeNames([
            'name' => 'اسم المنشأة',
            'manager' => 'مدير المشأة',
            'type' => 'الكيان القانوني',
            'tax_file' => 'الملف الضريبي',
            'tax_card' => 'البطاقة الضريبية',
            'trade_record' => 'السجل التجاري',
            'sales_tax' => 'ضريبة المبيعات',
            'opening_amount' => 'الرصيد الافتتاحى',
            //'country_sales_tax' => 'ضريبة المبيعات',
            'logo' => 'الشعار',
            'email' => 'البريد اﻻلكتروني',
        ]);

        return $validator;
    }

	/**
     * Show the facility information.
     *
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $facility = Facility::findOrFail($id);
        $facility->manager = User::find($facility->manager_id)->username;
        return view('facility.edit', compact('facility'));
    }

    public function update(Request $request, $id)
    {
        $facility = Facility::findOrFail($id);
        $validator = $this->validator( $request->all() );
        
        if( $validator->fails() ){
            
            return redirect()->back()->withInput()->with('error', 'حدث حطأ في حفظ البيانات.')->withErrors($validator);

        }else{
            if( !User::where('name', $request->manager)->exists() ){
                
                return redirect()->back()->withInput()->with('error', 'حدث حطأ في حفظ البيانات.')->withErrors(['manager' => 'خطأ في اسم مدير النمشأة.']);
            
            }

            $facility->name = $request->name;
            $facility->manager_id = User::where('username', $request->manager)->first()->id;
            $facility->type = $request->type;
            $facility->tax_file = $request->tax_file;
            $facility->tax_card = $request->tax_card;
            $facility->trade_record = $request->trade_record;
            $facility->sales_tax = $request->sales_tax;
            //logo
            if ($request->hasFile('logo')) {
                $facility->logo = $this->savePhoto($request->file('logo'));
            }
            $facility->country = $request->country;
            $facility->city = $request->city;
            $facility->region = $request->region;
            $facility->address = $request->address;
            $facility->website = $request->website;
            $facility->opening_amount = $request->opening_amount;
            $facility->save();

            return redirect()->back()->with('success', 'تم حفظ بيانات المنشأة.');
        }
    }

    /**
     * Move uploaded photo to public/img folder
     * @param  UploadedFile $photo
     * @return string
     */
    protected function savePhoto($photo)
    {
        $fileName = str_random(40) . '.' . $photo->guessClientExtension();
        $destinationPath = public_path() . DIRECTORY_SEPARATOR . 'uploads';
        $photo->move($destinationPath, $fileName);
        return $fileName;
    }
}