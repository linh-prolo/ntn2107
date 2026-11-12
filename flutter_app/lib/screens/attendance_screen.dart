import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import '../config/constants.dart';
import '../config/theme.dart';
import '../models/attendance.dart';
import '../providers/attendance_provider.dart';
import '../services/api_service.dart';
import '../services/location_service.dart';
import '../services/storage_service.dart';
import '../utils/formatters.dart';
import '../utils/image_data.dart';
import '../widgets/common.dart';
import '../widgets/status_badge.dart';

/// Kích thước ảnh gốc tối đa trước khi gửi (máy chủ nén lại ≤300KB).
const int _maxUploadBytes = 800000;

class AttendanceScreen extends StatefulWidget {
  const AttendanceScreen({super.key});

  @override
  State<AttendanceScreen> createState() => _AttendanceScreenState();
}

class _AttendanceScreenState extends State<AttendanceScreen> {
  final _location = const LocationService();
  final _picker = ImagePicker();
  Timer? _clock;
  DateTime _now = DateTime.now();
  String? _gpsMessage;
  bool _gpsError = false;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _clock = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) {
        setState(() => _now = context.read<AttendanceProvider>().now());
      }
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) context.read<AttendanceProvider>().load();
    });
  }

  @override
  void dispose() {
    _clock?.cancel();
    super.dispose();
  }

  void _setGps(String? message, {bool error = false}) {
    if (!mounted) return;
    setState(() {
      _gpsMessage = message;
      _gpsError = error;
    });
  }

  Future<void> _check(bool checkIn) async {
    final provider = context.read<AttendanceProvider>();
    final storage = context.read<StorageService>();
    final status = provider.status;
    if (status == null || _busy) return;
    setState(() => _busy = true);
    try {
      // 1. Vị trí GPS
      _setGps('📍 Đang lấy vị trí GPS...');
      final pos = await _location.currentPosition();
      final cfg = status.locationConfig;
      if (cfg.enabled) {
        final dist = _location.distance(
          cfg.lat,
          cfg.lng,
          pos.latitude,
          pos.longitude,
        );
        if (dist > cfg.radius) {
          _setGps(
            '❌ Bạn chưa có mặt tại vị trí ${cfg.name}. Khoảng cách hiện tại: '
            '${dist.round()}m (cho phép trong ${cfg.radius}m).',
            error: true,
          );
          return;
        }
        _setGps('✅ Đã xác minh vị trí (${dist.round()}m từ ${cfg.name}).');
      } else {
        _setGps('✅ Đã lấy vị trí GPS.');
      }

      // 2. Ảnh xác nhận
      final photo = await _picker.pickImage(
        source: ImageSource.camera,
        preferredCameraDevice: CameraDevice.front,
        maxWidth: 640,
        maxHeight: 640,
        imageQuality: 80,
      );
      if (photo == null) {
        _setGps('Đã hủy chụp ảnh. Cần ảnh xác nhận để chấm công.', error: true);
        return;
      }
      final bytes = await photo.readAsBytes();
      if (bytes.length > _maxUploadBytes) {
        _setGps('Ảnh quá lớn, vui lòng chụp lại.', error: true);
        return;
      }
      final dataUrl = imageDataUrl(bytes);
      if (dataUrl == null) {
        _setGps('Định dạng ảnh không hỗ trợ (chỉ JPG/PNG/WEBP).', error: true);
        return;
      }

      // 3. Xác nhận
      if (!mounted) return;
      final ok = await _confirm(checkIn, bytes);
      if (ok != true) return;

      // 4. Gửi
      final message = await provider.submit(
        checkIn: checkIn,
        lat: pos.latitude,
        lng: pos.longitude,
        deviceId: await storage.deviceId(),
        photoDataUrl: dataUrl,
      );
      _setGps(null);
      if (mounted) showAppSnackBar(context, message);
    } on LocationException catch (e) {
      _setGps(e.message, error: true);
    } on ApiException catch (e) {
      if (mounted) showAppSnackBar(context, e.message, error: true);
    } catch (_) {
      _setGps('Không thể mở camera/lấy ảnh. Vui lòng thử lại.', error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<bool?> _confirm(bool checkIn, Uint8List photo) {
    return showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(
          checkIn ? 'Xác nhận chấm công VÀO' : 'Xác nhận chấm công RA',
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(12),
              child: Image.memory(photo, height: 220, fit: BoxFit.cover),
            ),
            const SizedBox(height: 12),
            Text('Thời gian: ${Fmt.time(_now)} – ${Fmt.date(_now)}'),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Hủy'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(minimumSize: const Size(120, 44)),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Xác nhận'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final p = context.watch<AttendanceProvider>();
    final status = p.status;

    if (status == null) {
      if (p.error != null) {
        return Center(
          child: ErrorView(message: p.error!, onRetry: p.load),
        );
      }
      return const Center(
        child: LoadingView(message: 'Đang tải dữ liệu chấm công...'),
      );
    }

    return RefreshableList(
      onRefresh: p.load,
      children: [
        _clockCard(),
        _todayCard(status, p),
        _summaryGrid(status.summary),
        _historyCard(status, p),
      ],
    );
  }

  Widget _clockCard() {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF0D6EFD), Color(0xFF4F8DFD)],
        ),
        borderRadius: BorderRadius.circular(20),
        boxShadow: AppTheme.cardShadow,
      ),
      child: Column(
        children: [
          Text(
            '${Fmt.twoDigits(_now.hour)}:${Fmt.twoDigits(_now.minute)}:${Fmt.twoDigits(_now.second)}',
            style: const TextStyle(
              color: Colors.white,
              fontSize: 40,
              fontWeight: FontWeight.w800,
              fontFeatures: [FontFeature.tabularFigures()],
            ),
          ),
          const SizedBox(height: 4),
          Text(
            Fmt.weekdayDate(_now),
            style: const TextStyle(color: Colors.white70),
          ),
        ],
      ),
    );
  }

  Widget _todayCard(AttendanceStatus s, AttendanceProvider p) {
    final today = s.today;
    final busy = _busy || p.isSubmitting;
    Widget action;
    if (s.canCheckIn) {
      action = FilledButton.icon(
        key: const Key('check_in_button'),
        onPressed: busy ? null : () => _check(true),
        icon: busy ? _spinner() : const Icon(Icons.login),
        label: const Text('CHẤM CÔNG VÀO'),
      );
    } else if (s.canCheckOut) {
      action = FilledButton.icon(
        key: const Key('check_out_button'),
        style: FilledButton.styleFrom(backgroundColor: AppTheme.danger),
        onPressed: busy ? null : () => _check(false),
        icon: busy ? _spinner() : const Icon(Icons.logout),
        label: const Text('CHẤM CÔNG RA'),
      );
    } else {
      action = Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppTheme.success.withValues(alpha: 0.1),
          borderRadius: BorderRadius.circular(14),
        ),
        child: const Text(
          '✅ Bạn đã hoàn thành chấm công hôm nay.',
          textAlign: TextAlign.center,
          style: TextStyle(
            color: AppTheme.success,
            fontWeight: FontWeight.w600,
          ),
        ),
      );
    }

    return AppCard(
      title: 'Hôm nay',
      trailing: s.shift == null
          ? null
          : Pill(
              '${s.shift!.name} ${s.shift!.startTime}–${s.shift!.endTime}',
              color: AppTheme.primary,
            ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: _timeBox(
                  'Giờ vào',
                  today?.checkIn,
                  today?.isLate == true
                      ? 'Muộn ${today!.lateMinutes} phút'
                      : null,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _timeBox(
                  'Giờ ra',
                  today?.checkOut,
                  today?.earlyLeave == true
                      ? 'Về sớm ${today!.earlyLeaveMinutes} phút'
                      : null,
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          action,
          if (_gpsMessage != null) ...[
            const SizedBox(height: 12),
            Text(
              _gpsMessage!,
              key: const Key('gps_message'),
              textAlign: TextAlign.center,
              style: TextStyle(
                color: _gpsError ? AppTheme.danger : AppTheme.muted,
              ),
            ),
          ],
          if (s.locationConfig.enabled) ...[
            const SizedBox(height: 8),
            Text(
              'Phạm vi chấm công: ${s.locationConfig.name} (${s.locationConfig.radius}m)',
              textAlign: TextAlign.center,
              style: const TextStyle(color: AppTheme.muted, fontSize: 12),
            ),
          ],
        ],
      ),
    );
  }

  Widget _spinner() => const SizedBox(
    width: 18,
    height: 18,
    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
  );

  Widget _timeBox(String label, DateTime? time, String? warning) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
      decoration: BoxDecoration(
        color: AppTheme.background,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        children: [
          Text(
            label,
            style: const TextStyle(color: AppTheme.muted, fontSize: 12),
          ),
          const SizedBox(height: 4),
          Text(
            Fmt.time(time),
            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
          ),
          if (warning != null)
            Text(
              warning,
              style: const TextStyle(color: AppTheme.danger, fontSize: 12),
            ),
        ],
      ),
    );
  }

  Widget _summaryGrid(AttendanceSummary s) {
    Widget tile(String label, String value, Color color) => Expanded(
      child: AppCard(
        padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 6),
        child: Column(
          children: [
            Text(
              value,
              style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.w800,
                color: color,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              label,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 12, color: AppTheme.muted),
            ),
          ],
        ),
      ),
    );
    return Row(
      children: [
        tile('Ngày công', '${s.workDays}', AppTheme.primary),
        const SizedBox(width: 8),
        tile('Giờ làm', Fmt.number(s.workHours), AppTheme.success),
        const SizedBox(width: 8),
        tile('Đi muộn', '${s.lateDays}', AppTheme.danger),
        const SizedBox(width: 8),
        tile('Nghỉ phép', Fmt.number(s.leaveDays), const Color(0xFFB7791F)),
      ],
    );
  }

  Widget _historyCard(AttendanceStatus s, AttendanceProvider p) {
    return AppCard(
      title: 'Lịch sử chấm công',
      trailing: MonthSwitcher(
        month: p.month,
        year: p.year,
        onPrevious: p.previousMonth,
        onNext: p.isCurrentMonth ? null : p.nextMonth,
      ),
      child: p.isLoading
          ? const Center(child: LoadingView())
          : p.error != null
          ? ErrorView(message: p.error!, onRetry: p.load)
          : s.logs.isEmpty
          ? const EmptyView(
              message: 'Chưa có dữ liệu chấm công trong tháng này.',
            )
          : Column(children: [for (final log in s.logs) _logTile(log)]),
    );
  }

  Widget _logTile(AttendanceLog log) {
    final flag = log.locationFlag;
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: const BoxDecoration(
        border: Border(bottom: BorderSide(color: Color(0xFFEDF0F4))),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  log.workDate == null ? '-' : Fmt.weekdayDate(log.workDate!),
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 2),
                Text(
                  '${Fmt.time(log.checkIn)} → ${log.missingCheckout ? 'Thiếu giờ ra' : Fmt.time(log.checkOut)}',
                  style: TextStyle(
                    color: log.missingCheckout
                        ? AppTheme.danger
                        : AppTheme.muted,
                  ),
                ),
                const SizedBox(height: 4),
                Wrap(
                  spacing: 6,
                  runSpacing: 4,
                  children: [
                    if (log.isLate)
                      Pill('Muộn ${log.lateMinutes}p', color: AppTheme.danger),
                    if (log.earlyLeave)
                      Pill(
                        'Về sớm ${log.earlyLeaveMinutes}p',
                        color: const Color(0xFFB7791F),
                      ),
                    if (flag != null && flag != 'verified')
                      Pill(
                        AppConstants.locationFlags[flag] ?? flag,
                        color: AppTheme.muted,
                      ),
                  ],
                ),
              ],
            ),
          ),
          Text(
            '${Fmt.number(log.workHours)}h',
            style: const TextStyle(
              fontWeight: FontWeight.w700,
              color: AppTheme.primary,
            ),
          ),
        ],
      ),
    );
  }
}
