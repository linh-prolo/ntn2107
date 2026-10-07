import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../config/constants.dart';
import '../config/theme.dart';
import '../models/leave_request.dart';
import '../providers/leave_provider.dart';
import '../services/api_service.dart';
import '../utils/formatters.dart';
import '../widgets/common.dart';
import '../widgets/form_fields.dart';
import '../widgets/status_badge.dart';

class LeaveScreen extends StatefulWidget {
  const LeaveScreen({super.key, this.active = true});

  /// Chỉ tải dữ liệu khi tab đang được hiển thị.
  final bool active;

  @override
  State<LeaveScreen> createState() => _LeaveScreenState();
}

class _LeaveScreenState extends State<LeaveScreen> {
  String _type = 'annual';
  DateTime _from = DateTime.now();
  DateTime _to = DateTime.now();
  final _reason = TextEditingController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _ensureLoaded());
  }

  @override
  void didUpdateWidget(covariant LeaveScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.active && !oldWidget.active) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _ensureLoaded());
    }
  }

  void _ensureLoaded() {
    if (!mounted || !widget.active) return;
    final p = context.read<LeaveProvider>();
    if (!p.isLoaded && !p.isLoading) p.load();
  }

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  LeaveRequestInput get _input => LeaveRequestInput(
    leaveType: _type,
    startDate: _from,
    endDate: _to,
    reason: _reason.text,
  );

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    try {
      final msg = await context.read<LeaveProvider>().create(_input);
      if (!mounted) return;
      _reason.clear();
      showAppSnackBar(context, msg);
    } on ApiException catch (e) {
      if (mounted) showAppSnackBar(context, e.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = context.watch<LeaveProvider>();
    final submitting = p.isSubmitting;
    final days = _to.isBefore(_from) ? 0 : _input.totalDays;

    return RefreshableList(
      onRefresh: p.load,
      children: [
        AppCard(
          title: 'Đơn xin nghỉ phép',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              OptionsField(
                label: 'Loại nghỉ',
                value: _type,
                options: AppConstants.leaveTypes,
                enabled: !submitting,
                onChanged: (v) => setState(() {
                  _type = v;
                  if (v == 'annual') _to = _from;
                }),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: DateField(
                      label: 'Từ ngày',
                      value: _from,
                      enabled: !submitting,
                      onChanged: (d) => setState(() {
                        _from = d;
                        if (_type == 'annual' || _to.isBefore(d)) _to = d;
                      }),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: DateField(
                      label: 'Đến ngày',
                      value: _to,
                      firstDate: _from,
                      enabled: !submitting && _type != 'annual',
                      onChanged: (d) => setState(() => _to = d),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 6),
              Text(
                _type == 'annual'
                    ? 'Số ngày: $days · Phép năm tối đa 1 ngày mỗi tháng.'
                    : 'Số ngày: $days',
                style: const TextStyle(color: AppTheme.muted, fontSize: 12),
              ),
              const SizedBox(height: 12),
              AppTextField(
                key: const Key('leave_reason'),
                label: 'Lý do',
                controller: _reason,
                maxLines: 3,
                enabled: !submitting,
              ),
              const SizedBox(height: 16),
              FilledButton.icon(
                key: const Key('leave_submit'),
                onPressed: submitting ? null : _submit,
                icon: submitting
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.send),
                label: const Text('Gửi đơn'),
              ),
            ],
          ),
        ),
        AppCard(title: 'Lịch sử nghỉ phép', child: _history(p)),
      ],
    );
  }

  Widget _history(LeaveProvider p) {
    if (p.isLoading && p.items.isEmpty) {
      return const Center(child: LoadingView());
    }
    if (p.error != null) return ErrorView(message: p.error!, onRetry: p.load);
    if (p.items.isEmpty) {
      return const EmptyView(message: 'Chưa có đơn nghỉ phép nào.');
    }
    return Column(children: [for (final item in p.items) _LeaveTile(item)]);
  }
}

class _LeaveTile extends StatelessWidget {
  const _LeaveTile(this.item);

  final LeaveRequest item;

  @override
  Widget build(BuildContext context) {
    final range = item.startDate == item.endDate
        ? Fmt.date(item.startDate)
        : '${Fmt.date(item.startDate)} → ${Fmt.date(item.endDate)}';
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: const BoxDecoration(
        border: Border(bottom: BorderSide(color: Color(0xFFEDF0F4))),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  AppConstants.leaveTypeLabel(item.leaveType),
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
              ),
              StatusBadge(item.status),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            '$range · ${Fmt.number(item.totalDays)} ngày',
            style: const TextStyle(color: AppTheme.muted),
          ),
          if (item.reason.isNotEmpty) ...[
            const SizedBox(height: 2),
            Text(item.reason),
          ],
          if (item.status == 'rejected' &&
              (item.rejectReason ?? '').isNotEmpty) ...[
            const SizedBox(height: 2),
            Text(
              'Lý do từ chối: ${item.rejectReason}',
              style: const TextStyle(color: AppTheme.danger, fontSize: 13),
            ),
          ],
          if (item.approverName != null && item.status != 'pending')
            Text(
              'Người duyệt: ${item.approverName}',
              style: const TextStyle(color: AppTheme.muted, fontSize: 12),
            ),
        ],
      ),
    );
  }
}
