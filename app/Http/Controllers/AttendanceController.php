<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Http\Requests;
use App\Client;
use App\Supplier;
use App\ClientProcess;
use App\Employee;
use App\Expenses;
use App\SupplierProcess;
use App\Attendance;
use App\Facility;
use App\AbsentType;
use App\User;
use Validator;

class AttendanceController extends Controller {

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct() {
        $this->middleware('auth');
        //$this->middleware('ability:admin,deposit-withdraw');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        $attendances = Attendance::all();

        foreach ($attendances as $attendance) {
            //$attendance->check_out - $attendance->check_in
            $check_out = Carbon::parse($attendance->check_out);
            $check_in = Carbon::parse($attendance->check_in);
            //$attendance->check_out = $attendance->check_out
            $attendance->workingHours = $check_out->diffInHours($check_in);
            $attendance->employeeName = $attendance->employee->name;
            if ($attendance->process) {
                $attendance->processName = $attendance->process->name;
            } else {
                $attendance->processName = "عمليات ادارية";
            }
            if ($attendance->absentType) {
                $attendance->absentTypeName = $attendance->absentType->name;
            }
        }
        return view('attendance.index', compact(['attendances']));
    }

    protected function validator(array $data, $id = null) {
        $validator = Validator::make($data, [
                    'process_id' => 'exists:client_processes,id|required_without:is_managment_process',
                    'is_managment_process' => 'required_without:process_id',
                    'employee_id' => 'exists:employees,id|required',
                    'notes' => 'string'
        ]);

        $validator->setAttributeNames([
            'process_id' => 'اسم العملية',
            'employee_id' => 'اسم الموظف',
            'is_managment_process' => 'عمليات ادارية',
            'notes' => 'ملاحظات'
        ]);

        return $validator;
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create() {
        $employees = Employee::all();
        $processes = ClientProcess::allOpened()->get();
        $absentTypes = AbsentType::all();
        $employees_tmp = [];
        $employeesSalaries = [];
        $processes_tmp = [];
        $absentTypes_tmp = [];
        $absentTypesInfo = [];
        foreach ($employees as $employee) {
            $employees_tmp[$employee->id] = $employee->name;
            $employeesSalaries[$employee->id]['hourlySalary'] = $employee->daily_salary / $employee->working_hours;
        }
        foreach ($processes as $process) {
            $processes_tmp[$process->id] = $process->name;
        }
        foreach ($absentTypes as $type) {
            $absentTypes_tmp[$type->id] = $type->name;
            $absentTypesInfo[$type->id]['salaryDeduction'] = $type->salary_deduction;
            $absentTypesInfo[$type->id]['editable'] = $type->editable_deduction;
        }
        $processes = $processes_tmp;
        $employees = $employees_tmp;
        $absentTypes = $absentTypes_tmp;
        return view('attendance.create', compact(['employees', 'employeesSalaries', 'processes', 'absentTypes', 'absentTypesInfo']));
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
            return redirect()->back()->withInput($all)->with('error', 'حدث حطأ في حفظ البيانات.')->withErrors($validator);
        } else {
            $attendance = Attendance::firstOrCreate([
                            ["date", "=", $all['date']],
                            ["employee_id", "=", $all['employee_id']]
            ]);
            if (isset($request->is_managment_process)) {
                $all['process_id'] = null;
            }
            $attendance->update($all);
            return redirect()->route('attendance.edit', $attendance->id)->with(['success' => 'تم حفظ البيانات.']);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id) {
        $employees = Employee::all();
        $dt = Carbon::parse($request->date);
        $hourlyRate = 0;
        if ($id == "all") {
            $attendances = [];//Attendance::all();
        } else {
            $employee = Employee::findOrFail($id);
            $hourlyRate = $employee->daily_salary / $employee->working_hours;
            $attendances = Attendance::where([
                ['employee_id', '=', $id]
            ])->whereMonth('date', '=', $dt->month)->get();
        }
        $employees_tmp = [];
        $totalWorkingHours = 0;
        $totalSalaryDeduction = 0;
        $totalAbsentDeduction = 0;
        $totalBonuses = 0;
        $totalSalary = 0;
        foreach ($attendances as $attendance) {
            //$attendance->check_out - $attendance->check_in
            $check_out = Carbon::parse($attendance->check_out);
            $check_in = Carbon::parse($attendance->check_in);
            //$attendance->check_out = $attendance->check_out
            $attendance->workingHours = $check_out->diffInHours($check_in);
            $attendance->employeeName = $attendance->employee->name;
            if ($attendance->process) {
                $attendance->processName = $attendance->process->name;
            } else {
                $attendance->processName = "عمليات ادارية";
            }
            if ($attendance->absentType) {
                $attendance->absentTypeName = $attendance->absentType->name;
            }
            $totalWorkingHours += $attendance->workingHours;
            $totalSalaryDeduction += $attendance->salary_deduction;
            $totalAbsentDeduction += $attendance->absent_deduction;
            $totalBonuses += $attendance->mokaf;
        }
        $totalSalary = (($totalWorkingHours * $hourlyRate) + $totalBonuses) - ($totalSalaryDeduction + $totalAbsentDeduction);
        foreach ($employees as $employee) {
            $employees_tmp[$employee->id] = $employee->name;
        }
        $employees = $employees_tmp;
        return view('attendance.show', compact(['employees', 'attendances', "hourlyRate", "totalWorkingHours", "totalSalaryDeduction", "totalAbsentDeduction", "totalBonuses", "totalSalary"]));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id) {
        $attendance = Attendance::findOrFail($id);
        $check_out = Carbon::parse($attendance->check_out);
        $check_in = Carbon::parse($attendance->check_in);
        //$attendance->check_out = $attendance->check_out
        $attendance->workingHours = $check_out->diffInHours($check_in);
        $attendance->employeeName = $attendance->employee->name;
        if ($attendance->process) {
            $attendance->processName = $attendance->process->name;
        } else {
            $attendance->processName = "عمليات ادارية";
            $attendance->is_managment_process = TRUE;
        }
        if ($attendance->absentType) {
            $attendance->absentTypeName = $attendance->absentType->name;
        }
        $employees = Employee::all();
        $processes = ClientProcess::allOpened()->get();
        $absentTypes = AbsentType::all();
        $employees_tmp = [];
        $employeesSalaries = [];
        $processes_tmp = [];
        $absentTypes_tmp = [];
        $absentTypesInfo = [];
        foreach ($employees as $employee) {
            $employees_tmp[$employee->id] = $employee->name;
            $employeesSalaries[$employee->id]['hourlySalary'] = $employee->daily_salary / $employee->working_hours;
        }
        foreach ($processes as $process) {
            $processes_tmp[$process->id] = $process->name;
        }
        foreach ($absentTypes as $type) {
            $absentTypes_tmp[$type->id] = $type->name;
            $absentTypesInfo[$type->id]['salaryDeduction'] = $type->salary_deduction;
            $absentTypesInfo[$type->id]['editable'] = $type->editable_deduction;
        }
        $processes = $processes_tmp;
        $employees = $employees_tmp;
        $absentTypes = $absentTypes_tmp;
        return view('attendance.edit', compact(['attendance', 'employees', 'employeesSalaries', 'processes', 'absentTypes', 'absentTypesInfo']));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id) {
        $attendance = Attendance::findOrFail($id);
        $all = $request->all();
        $validator = $this->validator($all, $attendance->id);

        if ($validator->fails()) {
            return redirect()->back()->withInput($all)->with('error', 'حدث حطأ في حفظ البيانات.')->withErrors($validator);
        } else {
            $attendance->update($all);
            return redirect()->back()->withInput($all)->with(['success' => 'تم حفظ البيانات.']);
            
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id) {
        //
    }

    public function employee() {
        $employees = Employee::all();
        $attendances = Attendance::all();
        $employees_tmp = [];

        foreach ($attendances as $attendance) {
            //$attendance->check_out - $attendance->check_in
            $check_out = Carbon::parse($attendance->check_out);
            $check_in = Carbon::parse($attendance->check_in);
            //$attendance->check_out = $attendance->check_out
            $attendance->workingHours = $check_out->diffInHours($check_in);
            $attendance->employeeName = $attendance->employee->name;
            if ($attendance->process) {
                $attendance->processName = $attendance->process->name;
            } else {
                $attendance->processName = "عمليات ادارية";
            }
            if ($attendance->absentType) {
                $attendance->absentTypeName = $attendance->absentType->name;
            }
        }
        foreach ($employees as $employee) {
            $employees_tmp[$employee->id] = $employee->name;
        }
        $employees = $employees_tmp;
        return view('attendance.employee', compact(['employees', 'attendances']));
    }
}
