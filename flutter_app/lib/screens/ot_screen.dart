import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../config/constants.dart';
import '../config/theme.dart';
import '../models/ot_request.dart';
import '../providers/ot_provider.dart';
import '../services/api_service.dart';
import '../utils/formatters.dart';
import '../widgets/common.dart';
import '../widgets/form_fields.dart';
import '../widgets/status_badge.dart';

class OtScreen extends StatefulWidget {
  const OtScreen({super.key, this.active = true});

  /// Chỉ tải dữ liệu khi tab đang được hiển thị.
  final bool active;

  @override
  State<OtScreen> createState() => _OtScreenState();
}

class _OtScreenState extends State<OtScreen> {
  DateTime _date = DateTime.now();
  String _type = 'weekday';
  TimeOfDay _start = const TimeOfDay(hour: 17, minute: 30);
  final _hours = TextEditingController(text: '2');
  final _reason = TextEditingController();

  @override
  void initState() {
    super.initState();
    _type = _defaultType(_date);
    WidgetsBinding.instance.addPostFrameCallback((_) => _ensureLoaded());
  }

  @override
  void didUpdateWidget(covariant OtScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.active && !oldWidget.active) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _ensureLoaded());
    }
  }

  void _ensureLoaded() {
    if (!mounted || !widget.active) return;
    final p = context.read<OtProvider>();
    if (!p.isLoaded && !p.isLoading) p.load();
  }

  @override
  void dispose() {
    _hours.dispose();
    _reason.dispose();
    super.dispose();
  }

  static String _defaultType(DateTime d) =>
      d.weekday == DateTime.sunday ? 'weekend' : 'weekday';

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    final hours = double.tryParse(_hours.text.trim().replaceAll(',', '.')) ?? 0;
    final input = OtRequestInput(
      otDate: _date,
      otType: _type,
      startTime: TimeField.format(_start),
      hours: hours,
      reason: _reason.text,
    );
    try {
      final msg = await context.read<OtProvider>().create(input);
      if (!mounted) return;
      _reason.clear();
      showAppSnackBar(context, msg);
    } on ApiException catch (e) {
      if (mounted) showAppSnackBar(context, e.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = context.watch<OtProvider>();
    final today = DateTime.now();
    final submitting = p.isSubmitting;
    final hours = double.tryParse(_hours.text.trim().replaceAll(',', '.')) ?? 0;
    final endMinutes = _start.hour * 60 + _start.minute + (hours * 60).round();
    final endLabel = hours > 0
        ? '${Fmt.twoDigits((endMinutes ~/ 60) % 24)}:${Fmt.twoDigits(endMinutes % 60)}'
        : '--:--';

    return RefreshableList(
      onRefresh: p.load,
      children: [
        AppCard(
          title: 'Đăng ký làm thêm giờ',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              DateField(
                label: 'Ngày OT',
                value: _date,
                firstDate: DateTime(today.year, today.month, today.day),
                enabled: !submitting,
                onChanged: (d) => setState(() {
                  _date = d;
                  if (_type == 'weekday' || _type == 'weekend') {
                    _type = _defaultType(d);
                  }
                }),
              ),
              const SizedBox(height: 12),
              OptionsField(
                key: ValueKey('ot_type_$_type'),
                label: 'Loại OT',
                value: _type,
                options: AppConstants.otTypes,
                enabled: !submitting,
                onChanged: (v) => setState(() => _type = v),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: TimeField(
                      label: 'Giờ bắt đầu',
                      value: _start,
                      enabled: !submitting,
                      onChanged: (t) => setState(() => _start = t),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextField(
                      key: const Key('ot_hours'),
                      controller: _hours,
                      enabled: !submitting,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      onChanged: (_) => setState(() {}),
                      decoration: const InputDecoration(
                        labelText: 'Số giờ',
                        suffixText: 'giờ',
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 6),
              Text(
                'Kết thúc dự kiến: $endLabel (tối đa ${Fmt.number(AppConstants.maxOtHours)} giờ/ngày)',
                style: const TextStyle(color: AppTheme.muted, fontSize: 12),
              ),
              const SizedBox(height: 12),
              AppTextField(
                key: const Key('ot_reason'),
                label: 'Lý do / nội dung công việc',
                controller: _reason,
                maxLines: 3,
                enabled: !submitting,
              ),
              const SizedBox(height: 16),
              FilledButton.icon(
                key: const Key('ot_submit'),
                onPressed: submitting ? null : _submit,
                icon: submitting
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.send),
                label: const Text('Gửi đăng ký OT'),
              ),
            ],
          ),
        ),
        AppCard(
          title: 'Lịch sử OT',
          trailing: MonthSwitcher(
            month: p.month,
            year: p.year,
            onPrevious: p.previousMonth,
            onNext: p.nextMonth,
          ),
          child: _history(p),
        ),
      ],
    );
  }

  Widget _history(OtProvider p) {
    if (p.isLoading && p.items.isEmpty) {
      return const Center(child: LoadingView());
    }
    if (p.error != null) return ErrorView(message: p.error!, onRetry: p.load);
    if (p.items.isEmpty) {
      return const EmptyView(message: 'Chưa có đơn OT nào trong tháng này.');
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Tổng giờ OT đã duyệt: ${Fmt.number(p.approvedHours, decimals: 2)} giờ',
          style: const TextStyle(
            color: AppTheme.success,
            fontWeight: FontWeight.w600,
          ),
        ),
        const SizedBox(height: 4),
        for (final item in p.items) _OtTile(item),
      ],
    );
  }
}

class _OtTile extends StatelessWidget {
  const _OtTile(this.item);

  final OtRequest item;

  @override
  Widget build(BuildContext context) {
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
                  item.otDate == null ? '-' : Fmt.weekdayDate(item.otDate!),
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
              ),
              StatusBadge(item.status),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            '${AppConstants.otTypeLabel(item.otType)} · ${item.startTime} – ${item.endTime} · ${Fmt.number(item.hours, decimals: 2)} giờ',
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
