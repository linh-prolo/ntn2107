import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../config/theme.dart';
import '../models/payslip.dart';
import '../providers/payslip_provider.dart';
import '../utils/formatters.dart';
import '../widgets/common.dart';

class PayslipScreen extends StatefulWidget {
  const PayslipScreen({super.key, this.active = true});

  /// Chỉ tải dữ liệu khi tab đang được hiển thị.
  final bool active;

  @override
  State<PayslipScreen> createState() => _PayslipScreenState();
}

class _PayslipScreenState extends State<PayslipScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _ensureLoaded());
  }

  @override
  void didUpdateWidget(covariant PayslipScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.active && !oldWidget.active) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _ensureLoaded());
    }
  }

  void _ensureLoaded() {
    if (!mounted || !widget.active) return;
    final p = context.read<PayslipProvider>();
    if (!p.isLoaded && !p.isLoading) p.load();
  }

  @override
  Widget build(BuildContext context) {
    final p = context.watch<PayslipProvider>();

    if (!p.isLoaded) {
      if (p.error != null) {
        return Center(
          child: ErrorView(message: p.error!, onRetry: p.load),
        );
      }
      return const Center(
        child: LoadingView(message: 'Đang tải phiếu lương...'),
      );
    }

    if (p.items.isEmpty) {
      return RefreshableList(
        onRefresh: p.load,
        children: const [
          AppCard(
            child: EmptyView(
              icon: Icons.receipt_long_outlined,
              message: 'Chưa có phiếu lương nào được duyệt.',
            ),
          ),
        ],
      );
    }

    final slip = p.selected;
    return RefreshableList(
      onRefresh: p.load,
      children: [
        AppCard(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: DropdownButtonHideUnderline(
            child: DropdownButton<int>(
              key: const Key('payslip_period'),
              value: p.selectedId,
              isExpanded: true,
              items: [
                for (final s in p.items)
                  DropdownMenuItem(
                    value: s.id,
                    child: Text(
                      '${s.periodLabel} · ${Fmt.currency(s.netSalary)}',
                    ),
                  ),
              ],
              onChanged: (id) {
                if (id != null) p.select(id);
              },
            ),
          ),
        ),
        if (p.isLoadingDetail)
          const Center(child: LoadingView())
        else if (p.detailError != null)
          AppCard(
            child: ErrorView(
              message: p.detailError!,
              onRetry: () => p.select(p.selectedId!),
            ),
          )
        else if (slip != null)
          ..._detail(slip),
      ],
    );
  }

  List<Widget> _detail(Payslip s) {
    return [
      Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            colors: [Color(0xFF198754), Color(0xFF34B27B)],
          ),
          borderRadius: BorderRadius.circular(20),
          boxShadow: AppTheme.cardShadow,
        ),
        child: Column(
          children: [
            Text(
              'Thực lĩnh ${s.periodLabel.toLowerCase()}',
              style: const TextStyle(color: Colors.white70),
            ),
            const SizedBox(height: 4),
            Text(
              Fmt.currency(s.netSalary),
              key: const Key('payslip_net'),
              style: const TextStyle(
                color: Colors.white,
                fontSize: 30,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              '${Fmt.date(s.periodFrom)} – ${Fmt.date(s.periodTo)} · Công: ${Fmt.number(s.actualWorkdays)}/${s.workingDaysStandard}',
              style: const TextStyle(color: Colors.white70, fontSize: 12),
            ),
          ],
        ),
      ),
      AppCard(
        title: 'I. Lương cơ bản',
        child: Column(
          children: [
            _row('Lương cơ bản (hợp đồng)', s.basicSalary),
            _row('Lương theo ngày công', s.basicSalaryReceived, bold: true),
          ],
        ),
      ),
      _section('II. Phụ cấp', s.allowances, s.allowanceTotal),
      _section('III. Làm thêm giờ (OT)', s.otItems, s.otTotal),
      AppCard(
        title: 'IV. Tổng thu nhập',
        child: _row('Tổng thu nhập (gross)', s.grossSalary, bold: true),
      ),
      _section(
        'V. Các khoản khấu trừ',
        s.deductions,
        s.deductionTotal,
        negative: true,
      ),
      AppCard(
        title: 'VI. Thực lĩnh & chuyển khoản',
        child: Column(
          children: [
            _row('Thực lĩnh', s.netSalary, bold: true, color: AppTheme.success),
            if (s.advancePayment > 0) _row('Đã tạm ứng', -s.advancePayment),
            _row(
              'Chuyển khoản',
              s.bankTransfer,
              bold: true,
              color: AppTheme.primary,
            ),
            const SizedBox(height: 8),
            _textRow(
              'Ngân hàng',
              [
                s.bankName,
                s.bankBranch,
              ].whereType<String>().where((e) => e.isNotEmpty).join(' - '),
            ),
            _textRow(
              'Số tài khoản',
              (s.bankAccount ?? '').isEmpty ? '-' : s.bankAccount!,
            ),
            if ((s.remark ?? '').isNotEmpty) _textRow('Ghi chú', s.remark!),
            if (s.hasNetMismatch)
              const Padding(
                padding: EdgeInsets.only(top: 8),
                child: Text(
                  '⚠️ Số liệu chi tiết có chênh lệch với thực lĩnh. Vui lòng liên hệ phòng kế toán nếu cần đối chiếu.',
                  style: TextStyle(color: Color(0xFFB7791F), fontSize: 12),
                ),
              ),
          ],
        ),
      ),
    ];
  }

  Widget _section(
    String title,
    List<PayslipItem> items,
    double total, {
    bool negative = false,
  }) {
    final visible = items.where((e) => e.amount != 0).toList();
    return AppCard(
      title: title,
      child: Column(
        children: [
          if (visible.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 4),
              child: Text('Không có', style: TextStyle(color: AppTheme.muted)),
            ),
          for (final i in visible)
            _row(i.label, negative ? -i.amount : i.amount),
          const Divider(),
          _row('Tổng', negative ? -total : total, bold: true),
        ],
      ),
    );
  }

  Widget _row(String label, double amount, {bool bold = false, Color? color}) {
    final style = TextStyle(
      fontWeight: bold ? FontWeight.w700 : FontWeight.w400,
      color: color ?? (amount < 0 ? AppTheme.danger : AppTheme.text),
    );
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: Text(label, style: TextStyle(fontWeight: style.fontWeight)),
          ),
          Text(Fmt.currency(amount), style: style),
        ],
      ),
    );
  }

  Widget _textRow(String label, String value) {
    if (value.isEmpty) value = '-';
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 110,
            child: Text(label, style: const TextStyle(color: AppTheme.muted)),
          ),
          Expanded(child: Text(value, textAlign: TextAlign.right)),
        ],
      ),
    );
  }
}
