import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'auth.dart';
import 'screens/accounts_screen.dart';
import 'screens/add_transaction_screen.dart';
import 'screens/budget_screen.dart';
import 'screens/lock_screen.dart';
import 'screens/login_screen.dart';
import 'screens/settings_screen.dart';
import 'theme.dart';

void main() {
  runApp(const ProviderScope(child: BudgieApp()));
}

class BudgieApp extends ConsumerWidget {
  const BudgieApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final status = ref.watch(authProvider.select((auth) => auth.status));

    return MaterialApp(
      title: "Lil' Budgie",
      theme: budgieTheme(),
      home: switch (status) {
        AuthStatus.initializing =>
          const Scaffold(body: Center(child: CircularProgressIndicator())),
        AuthStatus.loggedOut => const LoginScreen(),
        AuthStatus.locked => const LockScreen(),
        AuthStatus.unlocked => const HomeScreen(),
      },
    );
  }
}

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _tab = 0;

  static const _screens = [
    BudgetScreen(),
    AccountsScreen(),
    AddTransactionScreen(),
    SettingsScreen(),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(index: _tab, children: _screens),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (index) => setState(() => _tab = index),
        destinations: const [
          NavigationDestination(
              icon: Icon(Icons.pie_chart_outline),
              selectedIcon: Icon(Icons.pie_chart),
              label: 'Budget'),
          NavigationDestination(
              icon: Icon(Icons.account_balance_outlined),
              selectedIcon: Icon(Icons.account_balance),
              label: 'Accounts'),
          NavigationDestination(
              icon: Icon(Icons.add_circle_outline),
              selectedIcon: Icon(Icons.add_circle),
              label: 'Add'),
          NavigationDestination(
              icon: Icon(Icons.settings_outlined),
              selectedIcon: Icon(Icons.settings),
              label: 'Settings'),
        ],
      ),
    );
  }
}
