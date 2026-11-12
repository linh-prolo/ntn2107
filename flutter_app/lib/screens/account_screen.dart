import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../config/api_config.dart';
import '../config/theme.dart';
import '../models/user.dart';
import '../providers/auth_provider.dart';
import '../utils/formatters.dart';
import '../widgets/common.dart';
import '../widgets/status_badge.dart';

class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key});

  Future<void> _logout(BuildContext context) async {
    final auth = context.read<AuthProvider>();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Đăng xuất'),
        content: const Text('Bạn có chắc muốn đăng xuất?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Hủy'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: TextButton.styleFrom(foregroundColor: AppTheme.danger),
            child: const Text('Đăng xuất'),
          ),
        ],
      ),
    );
    if (ok == true) await auth.logout();
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final User? user = auth.user;
    if (user == null) return const SizedBox.shrink();

    String orDash(String? v) => (v == null || v.trim().isEmpty) ? '-' : v;

    return RefreshableList(
      onRefresh: auth.refreshProfile,
      children: [
        AppCard(
          child: Column(
            children: [
              CircleAvatar(
                radius: 36,
                backgroundColor: AppTheme.primary,
                child: Text(
                  user.initial,
                  style: const TextStyle(
                    fontSize: 28,
                    color: Colors.white,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              const SizedBox(height: 12),
              Text(
                user.fullName,
                key: const Key('account_name'),
                style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                '${user.employeeCode} · ${orDash(user.departmentName)}',
                style: const TextStyle(color: AppTheme.muted),
              ),
              const SizedBox(height: 8),
              Pill(
                user.roleName.isEmpty ? user.role : user.roleName,
                color: AppTheme.primary,
              ),
            ],
          ),
        ),
        AppCard(
          title: 'Thông tin cá nhân',
          child: Column(
            children: [
              _info(Icons.person_outline, 'Tên đăng nhập', user.username),
              _info(Icons.email_outlined, 'Email', orDash(user.email)),
              _info(Icons.phone_outlined, 'Điện thoại', orDash(user.phone)),
              _info(
                Icons.cake_outlined,
                'Ngày sinh',
                Fmt.date(user.dateOfBirth),
              ),
              _info(
                Icons.work_outline,
                'Ngày vào làm',
                Fmt.date(user.dateJoined),
              ),
              _info(
                Icons.account_balance_outlined,
                'Ngân hàng',
                orDash(user.bankName),
              ),
              _info(
                Icons.credit_card,
                'Số tài khoản',
                orDash(user.bankAccount),
              ),
            ],
          ),
        ),
        OutlinedButton.icon(
          key: const Key('logout_button'),
          onPressed: () => _logout(context),
          style: OutlinedButton.styleFrom(
            foregroundColor: AppTheme.danger,
            side: const BorderSide(color: AppTheme.danger),
          ),
          icon: const Icon(Icons.logout),
          label: const Text('Đăng xuất'),
        ),
        Text(
          'Máy chủ: ${ApiConfig.baseUrl}',
          textAlign: TextAlign.center,
          style: const TextStyle(color: AppTheme.muted, fontSize: 12),
        ),
      ],
    );
  }

  Widget _info(IconData icon, String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        children: [
          Icon(icon, size: 20, color: AppTheme.muted),
          const SizedBox(width: 12),
          Expanded(
            child: Text(label, style: const TextStyle(color: AppTheme.muted)),
          ),
          Flexible(child: Text(value, textAlign: TextAlign.right)),
        ],
      ),
    );
  }
}
