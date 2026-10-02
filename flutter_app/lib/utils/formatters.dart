import 'package:intl/intl.dart';

/// Định dạng hiển thị tiếng Việt (giống formatDate / formatCurrency trong PHP).
class Fmt {
  Fmt._();

  static final DateFormat _date = DateFormat('dd/MM/yyyy');
  static final DateFormat _time = DateFormat('HH:mm');
  static final DateFormat _dateTime = DateFormat('dd/MM/yyyy HH:mm');
  static final DateFormat _apiDate = DateFormat('yyyy-MM-dd');
  static final NumberFormat _money = NumberFormat('#,##0', 'en_US');

  static String date(DateTime? d) => d == null ? '-' : _date.format(d);

  static String time(DateTime? d) => d == null ? '--:--' : _time.format(d);

  static String dateTime(DateTime? d) => d == null ? '-' : _dateTime.format(d);

  /// Ngày gửi lên API: yyyy-MM-dd.
  static String apiDate(DateTime d) => _apiDate.format(d);

  /// 8240000 -> "8.240.000 ₫"
  static String currency(num amount) =>
      '${_money.format(amount.round()).replaceAll(',', '.')} ₫';

  /// 2.5 -> "2,5"; 2 -> "2"
  static String number(num value, {int decimals = 1}) {
    final fixed = value.toStringAsFixed(decimals);
    final trimmed = fixed.contains('.')
        ? fixed.replaceFirst(RegExp(r'\.?0+$'), '')
        : fixed;
    return trimmed.replaceAll('.', ',');
  }

  static String twoDigits(int n) => n.toString().padLeft(2, '0');

  /// "Hôm nay" / "Hôm qua" / dd/MM/yyyy
  static String relativeDay(DateTime? d, {DateTime? now}) {
    if (d == null) return '-';
    final today = _dateOnly(now ?? DateTime.now());
    final day = _dateOnly(d);
    final diff = today.difference(day).inDays;
    if (diff == 0) return 'Hôm nay';
    if (diff == 1) return 'Hôm qua';
    return date(d);
  }

  static const List<String> _weekdays = [
    'Thứ Hai',
    'Thứ Ba',
    'Thứ Tư',
    'Thứ Năm',
    'Thứ Sáu',
    'Thứ Bảy',
    'Chủ Nhật',
  ];

  /// "Thứ Sáu, 02/10/2026"
  static String weekdayDate(DateTime d) =>
      '${_weekdays[d.weekday - 1]}, ${date(d)}';
}

DateTime _dateOnly(DateTime d) => DateTime(d.year, d.month, d.day);
