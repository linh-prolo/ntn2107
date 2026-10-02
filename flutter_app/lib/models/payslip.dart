import '../utils/json.dart';

/// Phiếu lương trong danh sách (GET /payslip).
class PayslipSummary {
  final int id;
  final int periodId;
  final int periodMonth;
  final int periodYear;
  final DateTime? periodFrom;
  final DateTime? periodTo;
  final double netSalary;
  final double bankTransfer;

  const PayslipSummary({
    required this.id,
    required this.periodId,
    required this.periodMonth,
    required this.periodYear,
    this.periodFrom,
    this.periodTo,
    this.netSalary = 0,
    this.bankTransfer = 0,
  });

  String get periodLabel => 'Tháng $periodMonth/$periodYear';

  factory PayslipSummary.fromJson(Map<String, dynamic> json) => PayslipSummary(
    id: asInt(json['id']),
    periodId: asInt(json['period_id']),
    periodMonth: asInt(json['period_month']),
    periodYear: asInt(json['period_year']),
    periodFrom: asDateTime(json['period_from']),
    periodTo: asDateTime(json['period_to']),
    netSalary: asDouble(json['net_salary']),
    bankTransfer: asDouble(json['bank_transfer']),
  );
}

/// 1 dòng trong phiếu lương (trợ cấp, OT, khấu trừ).
class PayslipItem {
  final String label;
  final double amount;

  const PayslipItem(this.label, this.amount);

  factory PayslipItem.fromJson(Map<String, dynamic> json) =>
      PayslipItem(asString(json['label']), asDouble(json['amount']));
}

/// Chi tiết phiếu lương (GET /payslip/{id}) – bố cục giống mobile/payslip.php.
class Payslip {
  final int id;
  final int periodMonth;
  final int periodYear;
  final DateTime? periodFrom;
  final DateTime? periodTo;
  final String fullName;
  final String employeeCode;
  final String? departmentName;
  final double actualWorkdays;
  final int workingDaysStandard;
  final double basicSalary;
  final double basicSalaryReceived;
  final List<PayslipItem> allowances;
  final double allowanceTotal;
  final List<PayslipItem> otItems;
  final double otTotal;
  final List<PayslipItem> deductions;
  final double deductionTotal;
  final double grossSalary;
  final double netSalary;
  final double advancePayment;
  final double bankTransfer;
  final String? bankName;
  final String? bankAccount;
  final String? bankBranch;
  final String? remark;

  const Payslip({
    required this.id,
    required this.periodMonth,
    required this.periodYear,
    this.periodFrom,
    this.periodTo,
    this.fullName = '',
    this.employeeCode = '',
    this.departmentName,
    this.actualWorkdays = 0,
    this.workingDaysStandard = 0,
    this.basicSalary = 0,
    this.basicSalaryReceived = 0,
    this.allowances = const [],
    this.allowanceTotal = 0,
    this.otItems = const [],
    this.otTotal = 0,
    this.deductions = const [],
    this.deductionTotal = 0,
    this.grossSalary = 0,
    this.netSalary = 0,
    this.advancePayment = 0,
    this.bankTransfer = 0,
    this.bankName,
    this.bankAccount,
    this.bankBranch,
    this.remark,
  });

  String get periodLabel => 'Tháng $periodMonth/$periodYear';

  String get bankLabel => [
    bankName,
    bankAccount,
  ].whereType<String>().where((s) => s.isNotEmpty).join(' ');

  /// Tổng các khoản hiển thị – để cảnh báo nếu lệch so với NET trong DB (giống bản web).
  double get calculatedNet =>
      basicSalaryReceived + allowanceTotal + otTotal - deductionTotal;

  bool get hasNetMismatch => (calculatedNet - netSalary).abs() > 1;

  factory Payslip.fromJson(Map<String, dynamic> json) {
    List<PayslipItem> items(String key) =>
        asMapList(json[key]).map(PayslipItem.fromJson).toList();
    return Payslip(
      id: asInt(json['id']),
      periodMonth: asInt(json['period_month']),
      periodYear: asInt(json['period_year']),
      periodFrom: asDateTime(json['period_from']),
      periodTo: asDateTime(json['period_to']),
      fullName: asString(json['full_name']),
      employeeCode: asString(json['employee_code']),
      departmentName: asStringOrNull(json['department_name']),
      actualWorkdays: asDouble(json['actual_workdays']),
      workingDaysStandard: asInt(json['working_days_standard']),
      basicSalary: asDouble(json['basic_salary']),
      basicSalaryReceived: asDouble(json['basic_salary_received']),
      allowances: items('allowances'),
      allowanceTotal: asDouble(json['allowance_total']),
      otItems: items('ot_items'),
      otTotal: asDouble(json['ot_total']),
      deductions: items('deductions'),
      deductionTotal: asDouble(json['deduction_total']),
      grossSalary: asDouble(json['gross_salary']),
      netSalary: asDouble(json['net_salary']),
      advancePayment: asDouble(json['advance_payment']),
      bankTransfer: asDouble(json['bank_transfer']),
      bankName: asStringOrNull(json['bank_name']),
      bankAccount: asStringOrNull(json['bank_account']),
      bankBranch: asStringOrNull(json['bank_branch']),
      remark: asStringOrNull(json['remark']),
    );
  }
}
