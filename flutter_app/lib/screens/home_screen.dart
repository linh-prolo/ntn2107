import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../providers/notification_provider.dart';
import '../widgets/bottom_nav_bar.dart';
import 'account_screen.dart';
import 'attendance_screen.dart';
import 'leave_screen.dart';
import 'notifications_screen.dart';
import 'ot_screen.dart';
import 'payslip_screen.dart';

/// Khung chính sau khi đăng nhập: 5 tab + chuông thông báo.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _index = 0;

  static const _titles = [
    'Chấm công',
    'Xin nghỉ phép',
    'Đăng ký OT',
    'Phiếu lương',
    'Tài khoản',
  ];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) context.read<NotificationProvider>().startPolling();
    });
  }

  void _openNotifications() {
    Navigator.of(
      context,
    ).push(MaterialPageRoute(builder: (_) => const NotificationsScreen()));
  }

  @override
  Widget build(BuildContext context) {
    final unread = context.select<NotificationProvider, int>(
      (p) => p.unreadCount,
    );
    return Scaffold(
      appBar: AppBar(
        title: Text(_titles[_index]),
        actions: [
          IconButton(
            key: const Key('open_notifications'),
            tooltip: 'Thông báo',
            onPressed: _openNotifications,
            icon: Badge(
              isLabelVisible: unread > 0,
              label: Text(unread > 99 ? '99+' : '$unread'),
              child: const Icon(Icons.notifications_outlined),
            ),
          ),
        ],
      ),
      body: IndexedStack(
        index: _index,
        children: [
          const AttendanceScreen(),
          LeaveScreen(active: _index == 1),
          OtScreen(active: _index == 2),
          PayslipScreen(active: _index == 3),
          const AccountScreen(),
        ],
      ),
      bottomNavigationBar: AppBottomNavBar(
        currentIndex: _index,
        onTap: (i) => setState(() => _index = i),
      ),
    );
  }
}
