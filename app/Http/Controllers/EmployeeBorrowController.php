<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests;
use App\Models\Employee;
use App\Models\EmployeeBorrow;
use App\Models\DepositWithdraw;
use App\Constants\EmployeeActions;
use App\Constants\PaymentMethods;
use App\Extensions\DateTime;
use Validator;

class EmployeeBorrowController extends Controller {

    public function __construct() {
        $this->middleware('auth');
        $this->middleware('ability:admin,employees-permissions');
    }

    protected function validator(array $data, $id = null) {
        $validator = Validator::make($data, [
                    'employee_id' => 'required|exists:employees,id',
                    'borrow_reason' => 'required_with:has_discount|string',
                    'amount' => 'required|numeric',
                    'pay_percentage' => 'numeric',
                    'pay_amount' => 'numeric'
        ]);

        $validator->setAttributeNames([
            'employee_id' => 'أسم الموظف',
            'borrow_reason' => 'سبب السلفية',
            'amount' => 'القيمة',
            'pay_percentage' => 'نسبة الخصم',
            'pay_amount' => 'قيمة الخصم'
        ]);

        return $validator;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        $employeeBorrows = EmployeeBorrow::all();

        return view('employee.borrow.index', compact('employeeBorrows'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create() {
        $employees = Employee::select('id', 'name', 'daily_salary')->where("borrow_system", 1)->get();
        $employees_tmp = [];
        $employeesSalaries = [];

        foreach ($employees as $employee) {
            $employees_tmp[$employee->id] = $employee->name;
            $employeesSalaries[$employee->id]['dailySalary'] = $employee->daily_salary;
        }
        $employees = $employees_tmp;
        return view('employee.borrow.create', compact(['employees', 'employeesSalaries']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request) {
        $validator = $this->validator($request->all());
        $all = $request->all();

        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', 'حدث حطأ في حفظ البيانات.')->withErrors($validator);
        } else {
            /* get employee info  */
            $employee = Employee::find($request->employee_id);

            /* Can't create new borrow if employee has payment lower than the borrow  */

            $all['is_active'] = TRUE;
            $employeeBorrow = EmployeeBorrow::create($all);

            $depositWithdraw = new DepositWithdraw();
            $depositWithdraw->withdrawValue = $employeeBorrow->pay_amount;
            $depositWithdraw->due_date = DateTime::now();
            $depositWithdraw->recordDesc = "سلفة مستديمة شهر {$depositWithdraw->due_date->month} سنة {$depositWithdraw->due_date->year}";
            $depositWithdraw->employee_id = $employee->id;
            $depositWithdraw->expenses_id = EmployeeActions::LongBorrow;
            $depositWithdraw->payMethod = PaymentMethods::CASH;
            $depositWithdraw->notes = DateTime::now();
            $depositWithdraw->save();


            return redirect()->route('employeeBorrow.index')->with('success', 'تم اضافة سلفية جديدة.');
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id) {
        
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id) {
        $borrow = EmployeeBorrow::findOrFail($id);
        $employee = Employee::where('id', $borrow->employee_id)->firstOrFail();
        $employees_tmp[$employee->id] = $employee->name;
        $employeesSalaries[$employee->id]['dailySalary'] = $employee->daily_salary;

        $employees = $employees_tmp;

        //return $employees_salary;
        return view('employee.borrow.edit', compact(['borrow', 'employees', 'employeesSalaries']));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id) {
        $employeeBorrow = EmployeeBorrow::findOrFail($id);
        $validator = $this->validator($request->all());


        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', 'حدث حطأ في حفظ البيانات.')->withErrors($validator);
        } else {
            //$employeeBorrow->id = $request->id;
            $employeeBorrow->employee_id = $request->employee_id;
            $employeeBorrow->amount = $request->amount;
            $employeeBorrow->borrow_reason = $request->borrow_reason;
            $employeeBorrow->pay_amount = $request->pay_amount;
            $employeeBorrow->save();

            return redirect()->back()->with('success', 'تم تعديل بيانات العميل.');
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id) {

        $employee = EmployeeBorrow::where('id', $id)->firstOrFail();
        $employee->delete();

        return redirect()->back()->with('success', 'تم حذف موظف.');
    }

}
