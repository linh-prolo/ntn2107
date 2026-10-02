import 'package:flutter/material.dart';

import '../config/constants.dart';
import '../config/theme.dart';

/// Nhãn màu nhỏ (pill).
class Pill extends StatelessWidget {
  const Pill(this.text, {super.key, required this.color, this.background});

  final String text;
  final Color color;
  final Color? background;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: background ?? color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        text,
        style: TextStyle(
          color: color,
          fontSize: 12,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}

/// Trạng thái đơn: Chờ duyệt / Đã duyệt / Từ chối (giống mobileStatusBadge()).
class StatusBadge extends StatelessWidget {
  const StatusBadge(this.status, {super.key});

  final String status;

  static Color colorOf(String status) => switch (status) {
    'approved' => AppTheme.success,
    'rejected' => AppTheme.danger,
    _ => const Color(0xFFB7791F),
  };

  @override
  Widget build(BuildContext context) =>
      Pill(AppConstants.statusLabel(status), color: colorOf(status));
}
