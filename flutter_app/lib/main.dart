import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:provider/provider.dart';

import 'config/constants.dart';
import 'config/theme.dart';
import 'providers/attendance_provider.dart';
import 'providers/auth_provider.dart';
import 'providers/leave_provider.dart';
import 'providers/notification_provider.dart';
import 'providers/ot_provider.dart';
import 'providers/payslip_provider.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';
import 'services/api_service.dart';
import 'services/auth_service.dart';
import 'services/notification_service.dart';
import 'services/storage_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final storage = await StorageService.create();
  runApp(NtnApp(storage: storage, api: ApiService()));
}

class NtnApp extends StatelessWidget {
  const NtnApp({super.key, required this.storage, required this.api});

  final StorageService storage;
  final ApiService api;

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        Provider<StorageService>.value(value: storage),
        Provider<ApiService>.value(value: api),
        ChangeNotifierProvider<AuthProvider>(
          create: (_) => AuthProvider(AuthService(api, storage), api)..init(),
        ),
      ],
      child: Consumer<AuthProvider>(
        builder: (context, auth, _) => MultiProvider(
          // Tạo mới dữ liệu nghiệp vụ mỗi khi đổi người dùng (đăng nhập/đăng xuất).
          key: ValueKey('session-${auth.user?.id}'),
          providers: [
            ChangeNotifierProvider(create: (_) => AttendanceProvider(api)),
            ChangeNotifierProvider(create: (_) => OtProvider(api)),
            ChangeNotifierProvider(create: (_) => LeaveProvider(api)),
            ChangeNotifierProvider(create: (_) => PayslipProvider(api)),
            ChangeNotifierProvider(
              create: (_) => NotificationProvider(NotificationService(api)),
            ),
          ],
          child: MaterialApp(
            title: AppConstants.appName,
            debugShowCheckedModeBanner: false,
            theme: AppTheme.light(),
            locale: const Locale('vi'),
            supportedLocales: const [Locale('vi'), Locale('en')],
            localizationsDelegates: const [
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            builder: (context, child) => ResponsiveFrame(child: child!),
            home: const AuthGate(),
          ),
        ),
      ),
    );
  }
}

/// Chọn màn hình theo trạng thái đăng nhập.
class AuthGate extends StatelessWidget {
  const AuthGate({super.key});

  @override
  Widget build(BuildContext context) {
    final status = context.select<AuthProvider, AuthStatus>((a) => a.status);
    switch (status) {
      case AuthStatus.unknown:
        return const Scaffold(body: Center(child: CircularProgressIndicator()));
      case AuthStatus.unauthenticated:
        return const LoginScreen();
      case AuthStatus.authenticated:
        return const HomeScreen();
    }
  }
}

/// Trên màn hình rộng (web/tablet) hiển thị ứng dụng ở cột giữa
/// giống giao diện mobile, trên điện thoại thì chiếm toàn màn hình.
class ResponsiveFrame extends StatelessWidget {
  const ResponsiveFrame({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    final width = mq.size.width;
    if (width <= AppConstants.maxContentWidth) return child;
    final frameWidth = math.min(width, AppConstants.maxContentWidth);
    return ColoredBox(
      color: const Color(0xFFE2E8F0),
      child: Center(
        child: SizedBox(
          width: frameWidth,
          child: DecoratedBox(
            decoration: const BoxDecoration(boxShadow: AppTheme.cardShadow),
            child: ClipRect(
              child: MediaQuery(
                data: mq.copyWith(size: Size(frameWidth, mq.size.height)),
                child: child,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
