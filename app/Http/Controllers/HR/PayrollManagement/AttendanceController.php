<?php

namespace App\Http\Controllers\HR\PayrollManagement;

use App\Constants\EmployeeActions;
use App\Constants\PaymentMethods;
use App\Extensions\DateTime;
use App\Helpers\Helpers;
use App\Http\Controllers\Controller;
use App\Models\AbsentType;
use App\Models\Attendance;
use App\Models\ClientProcess;
use App\Models\DepositWithdraw;
use App\Models\Employee;
use App\Models\EmployeeBorrowBilling;
use App\Reports\Employee\Salary;
use DB;
use Exception;
use Request;
use Auth;
use Validator;

/**
 * @Controller(prefix="attendance")
 * @Resource("attendance")
 * @Middleware({"web", "auth"})
 */
class AttendanceController extends Controller {

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        $startDate = DateTime::today()->format('Y-m-d 00:00:00');
        $endDate   = DateTime::today()->format('Y-m-d 23:59:59');

        return $this->getAttendanceItems('all', $startDate, $endDate, 1, TRUE);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     * @Get("attendanceSearch/{employee_id}", as="attendance.search")
     */
    public function search($employee_id, Request $request) {
        $user = Auth::user();
        if (!$user->ability('admin', 'attendance-edit')) {
            return response()->view('errors.403', [], 403);
        }
        $date = DateTime::parse($request['targetdate']);
        return $this->getAttendanceItems($employee_id, $date, null, 1);
    }

    private function getAttendanceItems($id, $startDate, $endDate, $canEdit, $isToday = FALSE) {
        $employees = Employee::all();
        $dt        = DateTime::parse($startDate);
        $hasData   = FALSE;
        if ($id == "all") {
            $attendances = []; //Attendance::all();
            $attendances = Attendance::whereBetween('date', [$startDate, $endDate])->orderBy('date', 'asc')->get();
            $employee_id = 0;
            $startDate   = null;
            $id          = 0;
        } else {
            $employee = Employee::findOrFail($id);
            if ($isToday) {
                $attendances = Attendance::whereBetween('date', [$startDate, $endDate])->orderBy('date', 'asc')->get();
            } else {
                $attendances = Attendance::where([
                            ['employee_id', '=', $id]
                        ])->whereMonth('date', '=', $dt->month)->get();
            }
            $hasData = TRUE;
        }
        $date        = $startDate;
        $employee_id = $id;

        foreach ($employees as $employee) {
            $employees_tmp[$employee->id] = $employee->name;
        }
        $employees = $employees_tmp;

        foreach ($attendances as $attendance) {
            $attendance->workingHours = $attendance->workingHoursToString();
            $attendance->employeeName = $attendance->employee->name;
            $attendance->date         = DateTime::parse($attendance->date)->format('l, d-m-Y');
            if ($attendance->process) {
                $attendance->processName = $attendance->process->name;
            } else {
                $attendance->processName = "عمليات ادارية";
            }
            if ($attendance->absentType) {
                $attendance->absentTypeName = $attendance->absentType->name;
            }
        }
        return view('attendance.index', compact(['employees', 'attendances', 'date', 'employee_id', 'hasData']));
    }

    protected function validatorCheckin(array $data, $id = null) {
        $validator = Validator::make($data, [
                    'process_id'           => 'required_without:is_managment_process',
                    'is_managment_process' => 'required_if:process_id,-1',
                    'employee_id'          => 'exists:employees,id|required',
                        //'notes' => 'string'
        ]);

        $validator->setAttributeNames([
            'process_id'           => 'اسم العملية',
            'employee_id'          => 'اسم الموظف',
            'is_managment_process' => 'عمليات ادارية',
                //'notes' => 'ملاحظات'
        ]);

        return $validator;
    }

    protected function validatorCheckout(array $data, $id = null) {
        $validator = Validator::make($data, [
                    'employee_id' => 'exists:employees,id|required',
                        //'notes' => 'string'
        ]);

        $validator->setAttributeNames([
            'employee_id' => 'اسم الموظف',
                //'notes' => 'ملاحظات'
        ]);

        return $validator;
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create() {
        $employees         = Employee::all();
        $processes         = ClientProcess::allOpened()->get();
        $absentTypes       = AbsentType::all();
        $employees_tmp     = [];
        $employeesSalaries = [];
        $processes_tmp     = [];
        $absentTypes_tmp   = [];
        $absentTypesInfo   = [];
        foreach ($employees as $employee) {
            $employees_tmp[$employee->id]                    = $employee->name;
            $employeesSalaries[$employee->id]['dailySalary'] = $employee->daily_salary;
        }
        foreach ($processes as $process) {
            $processes_tmp[$process->id] = $process->name;
        }
        foreach ($absentTypes as $type) {
            $absentTypes_tmp[$type->id]                    = $type->name;
            $absentTypesInfo[$type->id]['salaryDeduction'] = $type->salary_deduction;
            $absentTypesInfo[$type->id]['editable']        = $type->editable_deduction;
        }
        $processes   = $processes_tmp;
        $employees   = $employees_tmp;
        $absentTypes = $absentTypes_tmp;
        $checkin     = TRUE;
        return view('attendance.create', compact(['employees', 'employeesSalaries', 'processes', 'absentTypes', 'absentTypesInfo', 'checkin']));
    }

    /**
     * @return \Illuminate\Http\Response
     * @Get("checkin", as="attendance.checkin")
     */
    public function checkin() {
        return $this->createAttendance(TRUE);
    }

    /**
     * @return \Illuminate\Http\Response
     * @Get("checkout", as="attendance.checkout")
     */
    public function checkout() {
        return $this->createAttendance(FALSE);
    }

    /**
     * @return \Illuminate\Http\Response
     * @Get("manualadding", as="attendance.manualadding")
     */
    public function manualadding() {
        return $this->createAttendance();
    }

    function createAttendance($checkType = -1) {
        $employees             = Employee::all();
        $processes             = ClientProcess::allOpened()->get();
        $absentTypes           = AbsentType::all();
        $employees_tmp         = [];
        $employeesSalaries     = [];
        $processes_tmp         = [];
        $absentTypes_tmp       = [];
        $absentTypesInfo       = [];
        $employeesCheckinDates = [];
        foreach ($employees as $employee) {
            $employees_tmp[$employee->id]                    = $employee->name;
            $employeesSalaries[$employee->id]['dailySalary'] = $employee->daily_salary;
        }
        $processes_tmp[-1] = '';
        foreach ($processes as $process) {
            $processes_tmp[$process->id] = $process->name;
        }
        foreach ($absentTypes as $type) {
            $absentTypes_tmp[$type->id]                    = $type->name;
            $absentTypesInfo[$type->id]['salaryDeduction'] = $type->salary_deduction;
            $absentTypesInfo[$type->id]['editable']        = $type->editable_deduction;
        }
        $processes   = $processes_tmp;
        $employees   = $employees_tmp;
        $absentTypes = $absentTypes_tmp;
        $checkin     = $checkType;
        $checkinbtn  = FALSE;
        return view('attendance.create', compact(['employees', 'employeesSalaries', 'processes', 'absentTypes', 'absentTypesInfo', 'checkin', 'checkinbtn', 'employeesCheckinDates']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request) {
        $all = $request->all();
        if (!empty($request->check_in)) {
            $validator = $this->validatorCheckin($all);
        } else {
            $validator = $this->validatorCheckout($all);
        }
        if ($validator->fails()) {
            return redirect()->back()->withInput($all)->with('error', 'حدث حطأ في حفظ البيانات.')->withErrors($validator);
        } else {
            $attendance            = Attendance::where("employee_id", $all['employee_id'])
                    ->orderBy('id', 'desc')
                    ->first();
            $attendanceToday       = Attendance::where([
                        ["date", "=", $all['date']],
                        ["employee_id", "=", $all['employee_id']]
                    ])
                    ->orderBy('id', 'desc')
                    ->first();
            $attendanceBeforeToday = Attendance::where([
                        ["date", "=", DateTime::parse($all['date'])->addDay(-1)],
                        ["employee_id", "=", $all['employee_id']]
                    ])
                    ->orderBy('id', 'desc')
                    ->first();
            if ($request->checkin == -1) {
                $attendance          = Attendance::firstOrCreate([
                            ["date", "=", $all['date']],
                            ["employee_id", "=", $all['employee_id']]
                ]);
                $all['absent_check'] = TRUE;
                goto skip;
            } else if ($request->checkin == 0) {
                
            } else if (empty($attendance)) {
                if (isset($request->is_second_shift)) {
                    $errors['is_second_shift'] = "يجب تسجيل الوردية الاولى اولا";
                    return redirect()->back()->withInput($all)->with('error', 'حدث حطأ في حفظ البيانات.')->withErrors($errors);
                } else if (empty($request->check_in)) {
                    return redirect()->back()->withInput($all)->with('error', 'يجب تسجيل حضور اولا')->withErrors($validator);
                }
            } else if (!empty($attendanceToday)) {
                if (isset($attendanceToday->absent_check)) {
                    return redirect()->back()->withInput($all)->with('error', 'لقد تم تسجيل غياب لهذا العامل يرجى التعديل من شاشة التعديل');
                } else if (isset($attendanceToday->check_in) && isset($attendanceToday->check_out) && !isset($request->is_second_shift)) {
                    return redirect()->back()->withInput($all)->with('error', 'لقد تم تسجيل حضور و انصراف هذا العامل يرجى التعديل من شاشة التعديل');
                } else if (isset($request->check_in)) {
                    $checkinDate  = DateTime::parse($request->check_in);
                    $checkoutDate = DateTime::parse($attendanceToday->check_out);
                    if (empty($attendanceToday->check_out)) {
                        return redirect()->back()->withInput($all)->with('error', 'يجب تسجيل انصراف اولا');
                    } else if ($checkoutDate->gt($checkinDate)) {
                        return redirect()->back()->withInput($all)->with('error', 'تاريخ الدخول اكبر من اخر تاريخ انصراف');
                    }
                } else if (isset($request->check_out)) {
                    $checkinDate  = DateTime::parse($attendanceToday->check_in);
                    $checkoutDate = DateTime::parse($request->check_out);
                    if (isset($request->is_second_shift)) {
                        if ($attendanceToday->shift == 1) {
                            return redirect()->back()->withInput($all)->with('error', 'يجب تسجيل حضور الوردية الاولى اولا');
                        }
                    } else if (empty($attendanceToday->check_in)) {
                        return redirect()->back()->withInput($all)->with('error', 'يجب تسجيل حضور اولا');
                    } else if ($checkinDate->gt($checkoutDate)) {
                        return redirect()->back()->withInput($all)->with('error', 'تاريخ الانصراف اقل من اخر تاريخ دخول');
                    }
                }
                if (isset($attendanceToday->check_in) && isset($attendanceToday->check_out) && isset($request->is_second_shift)) {
                    $attendance   = Attendance::firstOrCreate([
                                ["date", "=", $all['date']],
                                ["employee_id", "=", $all['employee_id']],
                                ["shift", "=", 2]
                    ]);
                    $all['shift'] = 2;
                    goto skip;
                }
            } else if (empty($attendanceBeforeToday)) {
                return redirect()->back()->withInput($all)->with('error', 'يجب تسجيل اليوم السابق اولا');
            } else { // There are no checkin at this day
                if (isset($request->is_second_shift)) {
                    $errors['is_second_shift'] = "يجب تسجيل الوردية الاولى اولا";
                    return redirect()->back()->withInput($all)->with('error', 'حدث حطأ في حفظ البيانات.')->withErrors($errors);
                } else if (isset($request->check_in)) {
                    $checkinDate  = DateTime::parse($request->check_in);
                    $checkoutDate = DateTime::parse($attendance->check_out);
                    if (empty($attendance->check_out)) {
                        return redirect()->back()->withInput($all)->with('error', 'يجب تسجيل انصراف اولا');
                    } else if ($checkoutDate->gt($checkinDate)) {
                        return redirect()->back()->withInput($all)->with('error', 'تاريخ الدخول اكبر من اخر تاريخ انصراف');
                    }
                } else if (isset($request->check_out)) {
                    $checkinDate  = DateTime::parse($attendance->check_in);
                    $checkoutDate = DateTime::parse($request->check_out);
                    if (empty($attendance->check_in)) {
                        return redirect()->back()->withInput($all)->with('error', 'يجب تسجيل حضور اولا');
                    } else if ($checkinDate->gt($checkoutDate)) {
                        return redirect()->back()->withInput($all)->with('error', 'تاريخ الانصراف اقل من اخر تاريخ دخول');
                    }
                }
            }
            $attendance   = Attendance::firstOrCreate([
                        ["date", "=", $all['date']],
                        ["employee_id", "=", $all['employee_id']],
                        ["shift", "=", 1]
            ]);
            $all['shift'] = 1;
            skip:
            if (isset($request->is_managment_process)) {
                $all['process_id'] = null;
            }
            $attendance->update($all);
            if ($request->checkin) {
                return redirect()->route('attendance.checkin')->with(['success' => 'تم حفظ البيانات.']);
            } else {
                return redirect()->route('attendance.checkout')->with(['success' => 'تم حفظ البيانات.']);
            }
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id) {
        $employees  = Employee::all();
        $dt         = DateTime::parse($request->date);
        $hourlyRate = 0;
        $hasData    = FALSE;
        if ($id == "all") {
            $attendances = []; //Attendance::all();
            $employee_id = 0;
            $date        = null;
        } else {
            $employee    = Employee::findOrFail($id);
            $hourlyRate  = $employee->salaryPerHour();
            $attendances = Attendance::where([
                        ['employee_id', '=', $id]
                    ])->whereMonth('date', '=', $dt->month)->orderBy('date', 'asc')->get();
            $hasData     = TRUE;
        }
        $date                         = $request->date;
        $employee_id                  = $id;
        $employees_tmp                = [];
        $totalWorkingHours            = 0;
        $totalSalaryDeduction         = 0;
        $totalAbsentDeduction         = 0;
        $totalBonuses                 = 0;
        $totalHoursSalary             = 0;
        $totalSalary                  = 0;
        $totalNetSalary               = 0;
        $totalGuardianshipValue       = 0;
        $totalGuardianshipReturnValue = 0;
        $totalBorrowValue             = 0;
        $totalSmallBorrowValue        = 0;
        $totalLongBorrowValue         = 0;
        foreach ($attendances as $attendance) {
            $attendance->workingHours = $attendance->workingHoursToString();
            $attendance->employeeName = $attendance->employee->name;
            $attendance->date         = DateTime::parse($attendance->date)->format('l, d-m-Y');

            $attendance->GuardianshipValue       = $attendance->employeeGuardianship();
            $attendance->GuardianshipReturnValue = $attendance->employeeGuardianshipReturn();
            $attendance->borrowValue             = $attendance->employeeSmallBorrow();

            if ($attendance->process) {
                $attendance->processName = $attendance->process->name;
            } else {
                $attendance->processName = "عمليات ادارية";
            }
            if ($attendance->absentType) {
                $attendance->absentTypeName = $attendance->absentType->name;
            }
            $totalWorkingHours            += $attendance->workingHoursToSeconds();
            $totalSalaryDeduction         += $attendance->salary_deduction;
            $totalAbsentDeduction         += $attendance->absent_deduction;
            $totalBonuses                 += $attendance->mokaf;
            $totalGuardianshipValue       += $attendance->GuardianshipValue;
            $totalGuardianshipReturnValue += $attendance->GuardianshipReturnValue;
            $totalBorrowValue             += $attendance->borrowValue;
        }
        try {
            $attendances[0]->borrowValue = $attendances[0]->employeeLongBorrow();
            $totalLongBorrowValue        = $attendances[0]->employeeLongBorrow();
        } catch (Exception $exc) {
            
        }
        $totalSmallBorrowValue = $totalBorrowValue;
        $totalBorrowValue      += $totalLongBorrowValue;
        $totalHoursSalary      = $totalWorkingHours * (($hourlyRate / 60) / 60);
        $totalHoursSalary      = round($totalHoursSalary, 3);
        $totalWorkingHours     = Helpers::hoursMinutsToString($totalWorkingHours);
        $totalSalary           = ($totalHoursSalary + $totalBonuses);
        $totalNetSalary        = $totalSalary - ($totalSalaryDeduction + $totalAbsentDeduction + ($totalGuardianshipValue - $totalGuardianshipReturnValue) + $totalSmallBorrowValue + $totalLongBorrowValue);

        foreach ($employees as $employee) {
            $employees_tmp[$employee->id] = $employee->name;
        }
        $employees       = $employees_tmp;
        $salaryIsPaid    = FALSE;
        $depositWithdraw = DepositWithdraw::where([
                    ['employee_id', '=', $employee_id],
                    ['expenses_id', '=', EmployeeActions::TakeSalary]
                ])->whereMonth('notes', '=', $dt->month)->first();
        if (empty($depositWithdraw)) {
            $salaryIsPaid = TRUE;
        } else {
            $salaryIsPaid = FALSE;
        }
        return view('attendance.show', compact(['employees', 'attendances', "hourlyRate", "totalWorkingHours", "totalSalaryDeduction", "totalAbsentDeduction", "totalBonuses", "totalSalary", 'totalHoursSalary', 'totalNetSalary', 'totalGuardianshipValue', 'totalGuardianshipReturnValue', 'totalBorrowValue', 'totalLongBorrowValue', 'totalSmallBorrowValue', 'employee_id', 'date', 'hasData', 'salaryIsPaid']));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id) {
        $attendance               = Attendance::findOrFail($id);
        $check_out                = DateTime::parse($attendance->check_out);
        $check_in                 = DateTime::parse($attendance->check_in);
        //$attendance->check_out = $attendance->check_out
        $attendance->workingHours = $check_out->diffInHours($check_in);
        $attendance->employeeName = $attendance->employee->name;
        if ($attendance->process) {
            $attendance->processName = $attendance->process->name;
        } else {
            $attendance->processName          = "عمليات ادارية";
            $attendance->is_managment_process = TRUE;
        }
        if ($attendance->absentType) {
            $attendance->absentTypeName = $attendance->absentType->name;
        }
        $employees         = Employee::all();
        $processes         = ClientProcess::allOpened()->get();
        $absentTypes       = AbsentType::all();
        $employees_tmp     = [];
        $employeesSalaries = [];
        $processes_tmp     = [];
        $absentTypes_tmp   = [];
        $absentTypesInfo   = [];
        foreach ($employees as $employee) {
            $employees_tmp[$employee->id]                    = $employee->name;
            $employeesSalaries[$employee->id]['dailySalary'] = $employee->daily_salary;
        }
        foreach ($processes as $process) {
            $processes_tmp[$process->id] = $process->name;
        }
        foreach ($absentTypes as $type) {
            $absentTypes_tmp[$type->id]                    = $type->name;
            $absentTypesInfo[$type->id]['salaryDeduction'] = $type->salary_deduction;
            $absentTypesInfo[$type->id]['editable']        = $type->editable_deduction;
        }
        $processes   = $processes_tmp;
        $employees   = $employees_tmp;
        $absentTypes = $absentTypes_tmp;
        $checkin     = TRUE;
        return view('attendance.edit', compact(['attendance', 'employees', 'employeesSalaries', 'processes', 'absentTypes', 'absentTypesInfo', 'checkin']));
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
        $all        = $request->all();
        $validator  = $this->validatorCheckin($all, $attendance->id);

        if ($validator->fails()) {
            return redirect()->back()->withInput($all)->with('error', 'حدث حطأ في حفظ البيانات.')->withErrors($validator);
        } else {
            if (isset($request->is_managment_process)) {
                $all['process_id'] = null;
            }
            if (!isset($request->absent_check)) {
                $all['absent_check']     = null;
                $all['absent_type_id']   = 0;
                $all['absent_deduction'] = 0;
            }
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
        $attendance = Attendance::findOrFail($id);
        $attendance->delete();

        return redirect()->back()->with('success', 'تم الحذف .');
    }

    public function employee() {
        $employees     = Employee::all();
        $attendances   = Attendance::all();
        $employees_tmp = [];

        foreach ($attendances as $attendance) {
            //$attendance->check_out - $attendance->check_in
            $check_out = DateTime::parse($attendance->check_out);
            $check_in  = DateTime::parse($attendance->check_in);
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

    /**
     * @return \Illuminate\Http\Response
     * @Get("guardianship/{employee_id}", as="attendance.guardianship")
     */
    public function guardianship(Request $request, $employee_id) {
        $employees = Employee::all();
        $dt        = DateTime::parse($request->date);
        foreach ($employees as $employee) {
            $employees_tmp[$employee->id] = $employee->name;
        }
        if ($employee_id == "all") {
            $employeeGuardianships = []; //Attendance::all();
            $employee_id           = 0;
            $date                  = null;
        } else {
            $employee              = Employee::findOrFail($employee_id);
            $employeeGuardianships = $employee->employeeGuardianships($dt);
        }
        $date                         = $request->date;
        $employee_id                  = $employee_id;
        $totalGuardianshipValue       = 0;
        $totalGuardianshipReturnValue = 0;

        foreach ($employeeGuardianships as $guardianship) {
            $totalGuardianshipValue       += $guardianship->withdrawValue;
            $totalGuardianshipReturnValue += $guardianship->depositValue;
        }
        $employees = $employees_tmp;
        return view("attendance.guardianship", compact(['employees', 'employeeGuardianships', 'totalGuardianshipValue', 'totalGuardianshipReturnValue', 'employee_id', 'date']));
    }

    /**
     * @return \Illuminate\Http\Response
     * @Get("guardianshipaway/{employee_id}", as="attendance.guardianshipaway")
     */
    public function guardianshipaway(Request $request, $employee_id) {
        $employee               = Employee::findOrFail($employee_id);
        $newDate                = DateTime::parse($request->date);
        $newDate->addMonth(1);
        $depositWithdraw        = DepositWithdraw::findOrFail($employee->lastGuardianshipId());
        $depositWithdraw->notes = $newDate->startOfMonth();
        $depositWithdraw->save();
        return redirect()->back()->with('success', 'تم الترحيل');
    }

    /**
     * @return \Illuminate\Http\Response
     * @Get("guardianshipback/{employee_id}", as="attendance.guardianshipback")
     */
    public function guardianshipback(Request $request, $employee_id) {
        $employee               = Employee::findOrFail($employee_id);
        $depositWithdraw        = DepositWithdraw::findOrFail($employee->lastGuardianshipId());
        $depositWithdraw->notes = $depositWithdraw->due_date;
        $depositWithdraw->save();
        return redirect()->back()->with('success', 'تم الغاء ترحيل العهدة');
    }

    /**
     * @return \Illuminate\Http\Response
     * @Get("longBorrowaway/{employee_id}", as="attendance.longBorrowAway")
     */
    public function longBorrowAway(Request $request, $employee_id) {
        $date = DateTime::parse($request->date);

        $employeeBorrowBilling        = DB::table('employees')
                ->join('employee_borrows', 'employee_borrows.employee_id', '=', 'employees.id')
                ->join('employee_borrow_billing', 'employee_borrow_billing.employee_borrow_id', '=', 'employee_borrows.id')
                ->distinct()
                ->where([
                    ['paying_status', '=', EmployeeBorrowBilling::UN_PAID],
                    ['employees.id', '=', $employee_id]
                ])
                ->whereMonth('due_date', '>=', $date->month)
                ->select('employee_borrow_billing.id')
                ->get();
        $data                         = collect(EmployeeBorrowBilling::findOrFail($employeeBorrowBilling->first()->id))->except(['id']);
        $borrowBilling                = EmployeeBorrowBilling::create($data->all());
        $borrowBilling->paying_status = EmployeeBorrowBilling::POSTPONED;
        $borrowBilling->paid_date     = DateTime::now();
        $borrowBilling->save();

        foreach ($employeeBorrowBilling as $key => $emb) {
            $borrowBilling           = EmployeeBorrowBilling::findOrFail($emb->id);
            $borrowBilling->due_date = DateTime::parse($borrowBilling->due_date)->addMonth(1);
            $borrowBilling->save();
        }

        return redirect()->back()->with('success', 'تم الترحيل');
    }

    /**
     * @return \Illuminate\Http\Response
     * @Get("printSalaryReport/{employee}", as="attendance.printSalaryReport")
     */
    public function printSalaryReport(Request $request, Employee $employee) {
        $hourlyRate                   = 0;
        $totalWorkingHours            = 0;
        $totalSalaryDeduction         = 0;
        $totalAbsentDeduction         = 0;
        $totalBonuses                 = 0;
        $totalHoursSalary             = 0;
        $totalSalary                  = 0;
        $totalNetSalary               = 0;
        $totalGuardianshipValue       = 0;
        $totalGuardianshipReturnValue = 0;
        $totalBorrowValue             = 0;
        $totalSmallBorrowValue        = 0;
        $totalLongBorrowValue         = 0;

        $date        = DateTime::parse($request->date);
        $hourlyRate  = $employee->salaryPerHour();
        $attendances = Attendance::where([
                    ['employee_id', '=', $employee->id]
                ])->whereMonth('date', '=', $date->month)->get();

        $monthNum      = $date->month;
        $employees_tmp = [];
        foreach ($attendances as $attendance) {
            $attendance->workingHours            = $attendance->workingHoursToString();
            $attendance->employeeName            = $attendance->employee->name;
            $attendance->GuardianshipValue       = $attendance->employeeGuardianship();
            $attendance->GuardianshipReturnValue = $attendance->employeeGuardianshipReturn();
            $attendance->borrowValue             = $attendance->employeeSmallBorrow();

            if ($attendance->process) {
                $attendance->processName = $attendance->process->name;
            } else {
                $attendance->processName = "عمليات ادارية";
            }
            if ($attendance->absentType) {
                $attendance->absentTypeName = $attendance->absentType->name;
            }
            $totalWorkingHours            += $attendance->workingHoursToSeconds();
            $totalSalaryDeduction         += $attendance->salary_deduction;
            $totalAbsentDeduction         += $attendance->absent_deduction;
            $totalBonuses                 += $attendance->mokaf;
            $totalGuardianshipValue       += $attendance->GuardianshipValue;
            $totalGuardianshipReturnValue += $attendance->GuardianshipReturnValue;
            $totalBorrowValue             += $attendance->borrowValue;
        }
        try {
            $attendances[0]->borrowValue = $attendances[0]->employeeLongBorrow();
            $totalLongBorrowValue        = $attendances[0]->employeeLongBorrow();
        } catch (Exception $exc) {
            
        }
        $totalSmallBorrowValue = $totalBorrowValue;
        $totalBorrowValue      += $totalLongBorrowValue;
        $totalHoursSalary      = $totalWorkingHours * (($hourlyRate / 60) / 60);
        $totalHoursSalary      = round($totalHoursSalary, 3);
        $totalSalary           = ($totalHoursSalary + $totalBonuses);
        $totalNetSalary        = $totalSalary - ($totalSalaryDeduction + $totalAbsentDeduction + ($totalGuardianshipValue - $totalGuardianshipReturnValue) + $totalSmallBorrowValue + $totalLongBorrowValue);

        $employeeName = $employee->name;

        $pdfReport = new Salary(TRUE);

        $pdfReport->htmlContent = view('reports.employee.salary', compact(['employeeName', 'attendances', "hourlyRate", "totalWorkingHours", "totalSalaryDeduction", "totalAbsentDeduction", "totalBonuses", "totalSalary", 'totalHoursSalary', 'totalNetSalary', 'totalGuardianshipValue', 'totalGuardianshipReturnValue', 'totalBorrowValue', 'totalLongBorrowValue', 'totalSmallBorrowValue', 'employee_id', 'date', 'monthNum']))->render();

        return $pdfReport->exportPDF();
    }

    /**
     * @return \Illuminate\Http\Response
     * @Get("paySalary/{employee_id}", as="attendance.payEmpolyeeSalary")
     */
    public function payEmpolyeeSalary(Request $request, $employee_id) {
        try {
            $employee             = Employee::findOrFail($employee_id);
            $dt                   = DateTime::parse($request->date);
            $all['due_date']      = DateTime::today();
            $all['withdrawValue'] = $request->totalNetSalary;
            $all['recordDesc']    = "دفع مرتب {$employee->name}";
            $all['employee_id']   = $employee_id;
            $all['payMethod']     = PaymentMethods::CASH;
            $all['expenses_id']   = EmployeeActions::TakeSalary;
            $all['notes']         = $dt;

            $depositWithdraw = DepositWithdraw::where([
                        ['employee_id', '=', $employee_id],
                        ['expenses_id', '=', EmployeeActions::TakeSalary]
                    ])->whereMonth('notes', '=', $dt->month)->first();

            $employeeBorrowBilling = DB::table('employees')
                    ->join('employee_borrows', 'employee_borrows.employee_id', '=', 'employees.id')
                    ->join('employee_borrow_billing', 'employee_borrow_billing.employee_borrow_id', '=', 'employee_borrows.id')
                    ->distinct()
                    ->where([
                        ['paying_status', '=', EmployeeBorrowBilling::UN_PAID],
                        ['employees.id', '=', $employee_id]
                    ])
                    ->whereMonth('due_date', $dt->month)
                    ->select('employee_borrow_billing.id')
                    ->first();
            if (!empty($employeeBorrowBilling->id)) {
                $borrowBilling                = EmployeeBorrowBilling::findOrFail($employeeBorrowBilling->id);
                $borrowBilling->paying_status = EmployeeBorrowBilling::PAID;
                $borrowBilling->paid_amount   += $borrowBilling->getRemaining();
                $borrowBilling->paid_date     = $all['due_date'];
                $borrowBilling->save();
            }

            if (empty($depositWithdraw)) {
                DepositWithdraw::create($all);
                return redirect()->back()->with('success', 'تم دفع المرتب');
            } else {
                return redirect()->back()->withInput($request->all())->with('error', 'لقد تم دفع المرتب من قبل');
            }
        } catch (Exception $exc) {
            return redirect()->back()->withInput($request->all())->with('error', 'حدث حطأ في حفظ البيانات.');
        }
    }

    /**
     * @return \Illuminate\Http\Response
     * @Get("getEmployeesCheckinDate", as="attendance.getEmployeesCheckinDate")
     */
    public function getEmployeesCheckinDate(Request $request) {
        $shift      = ($request->is_second_shift == "true" ? 2 : 1);
        $attendance = Attendance::where([
                    ["date", "=", $request->date],
                    ["employee_id", "=", $request->employee_id],
                    ["shift", "=", $shift]
                ])->first();
        if (empty($attendance))
            return "";
        else
            return $attendance->check_in;
    }

    private function getEmployeeData() {
        $employees  = Employee::all();
        $dt         = DateTime::parse($request->date);
        $hourlyRate = 0;
        $hasData    = FALSE;
        if ($id == "all") {
            $attendances = []; //Attendance::all();
            $employee_id = 0;
            $date        = null;
        } else {
            $employee    = Employee::findOrFail($id);
            $hourlyRate  = $employee->salaryPerHour();
            $attendances = Attendance::where([
                        ['employee_id', '=', $id]
                    ])->whereMonth('date', '=', $dt->month)->orderBy('date', 'asc')->get();
            $hasData     = TRUE;
        }
        $date                         = $request->date;
        $employee_id                  = $id;
        $employees_tmp                = [];
        $totalWorkingHours            = 0;
        $totalSalaryDeduction         = 0;
        $totalAbsentDeduction         = 0;
        $totalBonuses                 = 0;
        $totalHoursSalary             = 0;
        $totalSalary                  = 0;
        $totalNetSalary               = 0;
        $totalGuardianshipValue       = 0;
        $totalGuardianshipReturnValue = 0;
        $totalBorrowValue             = 0;
        $totalSmallBorrowValue        = 0;
        $totalLongBorrowValue         = 0;
        foreach ($attendances as $attendance) {
            $attendance->workingHours = $attendance->workingHoursToString();
            $attendance->employeeName = $attendance->employee->name;
            $attendance->date         = DateTime::parse($attendance->date)->format('l, d-m-Y');

            $attendance->GuardianshipValue       = $attendance->employeeGuardianship();
            $attendance->GuardianshipReturnValue = $attendance->employeeGuardianshipReturn();
            $attendance->borrowValue             = $attendance->employeeSmallBorrow();

            if ($attendance->process) {
                $attendance->processName = $attendance->process->name;
            } else {
                $attendance->processName = "عمليات ادارية";
            }
            if ($attendance->absentType) {
                $attendance->absentTypeName = $attendance->absentType->name;
            }
            $totalWorkingHours            += $attendance->workingHoursToSeconds();
            $totalSalaryDeduction         += $attendance->salary_deduction;
            $totalAbsentDeduction         += $attendance->absent_deduction;
            $totalBonuses                 += $attendance->mokaf;
            $totalGuardianshipValue       += $attendance->GuardianshipValue;
            $totalGuardianshipReturnValue += $attendance->GuardianshipReturnValue;
            $totalBorrowValue             += $attendance->borrowValue;
        }
        /* try {
          $attendances[0]->borrowValue = $attendances[0]->employeeLongBorrow();
          $totalLongBorrowValue = $attendances[0]->employeeLongBorrow();
          } catch (\Exception $exc) {

          } */
        $totalSmallBorrowValue = $totalBorrowValue;
        $totalBorrowValue      += $totalLongBorrowValue;
        $totalHoursSalary      = $totalWorkingHours * (($hourlyRate / 60) / 60);
        $totalHoursSalary      = round($totalHoursSalary, 3);
        $totalWorkingHours     = Helpers::hoursMinutsToString($totalWorkingHours);
        $totalSalary           = ($totalHoursSalary + $totalBonuses);
        $totalNetSalary        = $totalSalary - ($totalSalaryDeduction + $totalAbsentDeduction + ($totalGuardianshipValue - $totalGuardianshipReturnValue) + $totalSmallBorrowValue + $totalLongBorrowValue);

        foreach ($employees as $employee) {
            $employees_tmp[$employee->id] = $employee->name;
        }
        $employees = $employees_tmp;
        return view('attendance.show', compact(['employees', 'attendances', "hourlyRate", "totalWorkingHours", "totalSalaryDeduction", "totalAbsentDeduction", "totalBonuses", "totalSalary", 'totalHoursSalary', 'totalNetSalary', 'totalGuardianshipValue', 'totalGuardianshipReturnValue', 'totalBorrowValue', 'totalLongBorrowValue', 'totalSmallBorrowValue', 'employee_id', 'date', 'hasData']));
    }

}