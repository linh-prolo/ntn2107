import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../config/theme.dart';
import '../models/notification.dart';
import '../providers/notification_provider.dart';
import '../services/api_service.dart';
import '../utils/formatters.dart';
import '../widgets/common.dart';

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) context.read<NotificationProvider>().load();
    });
  }

  Future<void> _run(Future<void> Function() action) async {
    try {
      await action();
    } on ApiException catch (e) {
      if (mounted) showAppSnackBar(context, e.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = context.watch<NotificationProvider>();
    return Scaffold(
      appBar: AppBar(
        title: const Text('Thông báo'),
        actions: [
          if (p.unreadCount > 0)
            IconButton(
              key: const Key('mark_all_read'),
              tooltip: 'Đánh dấu tất cả đã đọc',
              icon: const Icon(Icons.done_all),
              onPressed: () => _run(p.markAllRead),
            ),
        ],
      ),
      body: _body(p),
    );
  }

  Widget _body(NotificationProvider p) {
    if (!p.isLoaded) {
      if (p.error != null) {
        return Center(
          child: ErrorView(message: p.error!, onRetry: p.load),
        );
      }
      return const Center(child: LoadingView());
    }
    if (p.items.isEmpty) {
      return RefreshableList(
        onRefresh: p.load,
        children: const [
          EmptyView(
            icon: Icons.notifications_none,
            message: 'Bạn chưa có thông báo nào.',
          ),
        ],
      );
    }
    return RefreshIndicator(
      onRefresh: p.load,
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.symmetric(vertical: 8),
        itemCount: p.items.length,
        separatorBuilder: (_, _) => const Divider(height: 1),
        itemBuilder: (_, i) => _tile(p, p.items[i]),
      ),
    );
  }

  Widget _tile(NotificationProvider p, AppNotification n) {
    return Material(
      color: n.isRead
          ? Colors.transparent
          : AppTheme.primary.withValues(alpha: 0.06),
      child: ListTile(
        onTap: () => _run(() => p.markRead(n)),
        leading: CircleAvatar(
          backgroundColor: _color(n.type).withValues(alpha: 0.12),
          child: Icon(_icon(n.type), color: _color(n.type), size: 20),
        ),
        title: Text(
          n.title,
          style: TextStyle(
            fontWeight: n.isRead ? FontWeight.w500 : FontWeight.w700,
          ),
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const SizedBox(height: 2),
            Text(n.message),
            const SizedBox(height: 4),
            Text(
              '${Fmt.relativeDay(n.createdAt)} ${Fmt.time(n.createdAt)}',
              style: const TextStyle(fontSize: 12, color: AppTheme.muted),
            ),
          ],
        ),
        trailing: n.isRead
            ? null
            : const Icon(Icons.circle, size: 10, color: AppTheme.primary),
      ),
    );
  }

  static IconData _icon(String type) => switch (type) {
    'success' => Icons.check_circle_outline,
    'danger' || 'error' => Icons.cancel_outlined,
    'warning' => Icons.warning_amber_outlined,
    _ => Icons.notifications_outlined,
  };

  static Color _color(String type) => switch (type) {
    'success' => AppTheme.success,
    'danger' || 'error' => AppTheme.danger,
    'warning' => const Color(0xFFB7791F),
    _ => AppTheme.primary,
  };
}
