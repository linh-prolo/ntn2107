import '../utils/formatters.dart';
import '../utils/json.dart';

/// Nhân viên đang đăng nhập (bảng users + employee_profiles).
class User {
  final int id;
  final String employeeCode;
  final String fullName;
  final String username;
  final String? email;
  final String? phone;
  final String role;
  final String roleName;
  final int? departmentId;
  final String? departmentName;
  final DateTime? dateOfBirth;
  final DateTime? dateJoined;
  final String? identityNo;
  final String? bankAccount;
  final String? bankName;
  final String? bankBranch;

  const User({
    required this.id,
    required this.employeeCode,
    required this.fullName,
    required this.username,
    this.email,
    this.phone,
    this.role = '',
    this.roleName = '',
    this.departmentId,
    this.departmentName,
    this.dateOfBirth,
    this.dateJoined,
    this.identityNo,
    this.bankAccount,
    this.bankName,
    this.bankBranch,
  });

  factory User.fromJson(Map<String, dynamic> json) => User(
    id: asInt(json['id']),
    employeeCode: asString(json['employee_code']),
    fullName: asString(json['full_name']),
    username: asString(json['username']),
    email: asStringOrNull(json['email']),
    phone: asStringOrNull(json['phone']),
    role: asString(json['role']),
    roleName: asString(json['role_name']),
    departmentId: asIntOrNull(json['department_id']),
    departmentName: asStringOrNull(json['department_name']),
    dateOfBirth: asDateTime(json['date_of_birth']),
    dateJoined: asDateTime(json['date_joined']),
    identityNo: asStringOrNull(json['identity_no']),
    bankAccount: asStringOrNull(json['bank_account']),
    bankName: asStringOrNull(json['bank_name']),
    bankBranch: asStringOrNull(json['bank_branch']),
  );

  Map<String, dynamic> toJson() => {
    'id': id,
    'employee_code': employeeCode,
    'full_name': fullName,
    'username': username,
    'email': email,
    'phone': phone,
    'role': role,
    'role_name': roleName,
    'department_id': departmentId,
    'department_name': departmentName,
    'date_of_birth': _date(dateOfBirth),
    'date_joined': _date(dateJoined),
    'identity_no': identityNo,
    'bank_account': bankAccount,
    'bank_name': bankName,
    'bank_branch': bankBranch,
  };

  /// Chữ cái đầu để hiển thị avatar (giống mobileUserInitial()).
  String get initial {
    final name = fullName.trim();
    return name.isEmpty ? 'U' : name.substring(0, 1).toUpperCase();
  }

  static String? _date(DateTime? d) => d == null ? null : Fmt.apiDate(d);
}
