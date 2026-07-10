import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth.dart';
import '../providers.dart';

class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authProvider);
    final budgets = ref.watch(budgetsProvider).value ?? [];
    final current = ref.watch(currentBudgetProvider).value;

    return Scaffold(
      appBar: AppBar(title: const Text('Settings'), centerTitle: true),
      body: ListView(
        children: [
          ListTile(
            leading: const Icon(Icons.person_outline),
            title: Text(auth.userName ?? ''),
            subtitle: Text(auth.userEmail ?? ''),
          ),
          const Divider(),
          if (budgets.length > 1)
            ListTile(
              leading: const Icon(Icons.account_balance_wallet_outlined),
              title: const Text('Budget'),
              trailing: DropdownButton<String>(
                value: current?.uuid,
                items: [
                  for (final budget in budgets)
                    DropdownMenuItem(value: budget.uuid, child: Text(budget.name)),
                ],
                onChanged: (uuid) {
                  if (uuid != null) {
                    ref.read(selectedBudgetUuidProvider.notifier).select(uuid);
                  }
                },
              ),
            )
          else
            ListTile(
              leading: const Icon(Icons.account_balance_wallet_outlined),
              title: const Text('Budget'),
              subtitle: Text(current?.name ?? '—'),
            ),
          SwitchListTile(
            secondary: const Icon(Icons.fingerprint),
            title: const Text('Biometric unlock'),
            subtitle: const Text('Require Face ID / fingerprint on open'),
            value: auth.biometricsEnabled,
            onChanged: (enabled) =>
                ref.read(authProvider.notifier).setBiometrics(enabled),
          ),
          ListTile(
            leading: const Icon(Icons.dns_outlined),
            title: const Text('Server'),
            subtitle: Text(auth.serverUrl),
          ),
          const Divider(),
          ListTile(
            leading: Icon(Icons.logout, color: Colors.red.shade700),
            title: Text('Sign out', style: TextStyle(color: Colors.red.shade700)),
            onTap: () => ref.read(authProvider.notifier).logout(),
          ),
        ],
      ),
    );
  }
}
