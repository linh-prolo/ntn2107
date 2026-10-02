import 'package:flutter/material.dart';

/// Một mục trên thanh điều hướng dưới (giống mobileBottomNav()).
class NavItem {
  final String label;
  final IconData icon;
  final IconData activeIcon;

  const NavItem(this.label, this.icon, this.activeIcon);
}

const List<NavItem> appNavItems = [
  NavItem('Chấm công', Icons.access_time, Icons.access_time_filled),
  NavItem('Xin phép', Icons.event_available_outlined, Icons.event_available),
  NavItem('OT', Icons.more_time_outlined, Icons.more_time),
  NavItem('Lương', Icons.payments_outlined, Icons.payments),
  NavItem('Tôi', Icons.person_outline, Icons.person),
];

class AppBottomNavBar extends StatelessWidget {
  const AppBottomNavBar({
    super.key,
    required this.currentIndex,
    required this.onTap,
  });

  final int currentIndex;
  final ValueChanged<int> onTap;

  @override
  Widget build(BuildContext context) {
    return NavigationBar(
      selectedIndex: currentIndex,
      onDestinationSelected: onTap,
      height: 64,
      backgroundColor: Colors.white,
      labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
      destinations: [
        for (final item in appNavItems)
          NavigationDestination(
            icon: Icon(item.icon),
            selectedIcon: Icon(item.activeIcon),
            label: item.label,
          ),
      ],
    );
  }
}
