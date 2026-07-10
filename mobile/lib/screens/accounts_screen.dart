import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth.dart';
import '../models.dart';
import '../money.dart';
import '../providers.dart';
import '../theme.dart';
import 'register_screen.dart';

class AccountsScreen extends ConsumerWidget {
  const AccountsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final accounts = ref.watch(accountsProvider);
    final budget = ref.watch(currentBudgetProvider).value;

    return Scaffold(
      appBar: AppBar(title: const Text('Accounts'), centerTitle: true),
      body: accounts.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(child: Text(apiErrorMessage(e))),
        data: (list) {
          final open = list.where((a) => !a.closed).toList();
          if (open.isEmpty) {
            return const Center(
                child: Text('No accounts yet — add one on the web app.'));
          }
          final onBudget = open.where((a) => a.onBudget).toList();
          final tracking = open.where((a) => !a.onBudget).toList();

          return RefreshIndicator(
            onRefresh: () async {
              ref.invalidate(accountsProvider);
              await ref.read(accountsProvider.future);
            },
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: [
                _section(context, 'Budget accounts', onBudget, budget, ref),
                if (tracking.isNotEmpty)
                  _section(context, 'Tracking', tracking, budget, ref),
              ],
            ),
          );
        },
      ),
    );
  }

  Widget _section(BuildContext context, String title, List<Account> accounts,
      Budget? budget, WidgetRef ref) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
          child: Text(title.toUpperCase(),
              style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  color: BudgieColors.mist, letterSpacing: 1.2)),
        ),
        for (final account in accounts)
          ListTile(
            title: Text(account.name),
            subtitle: Text(account.type),
            trailing: Text(
              formatMoney(account.balance, currency: budget?.currency ?? 'AUD'),
              style: TextStyle(
                fontWeight: FontWeight.w600,
                color: account.balance < 0
                    ? BudgieColors.moneyNegative
                    : BudgieColors.moneyPositive,
              ),
            ),
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute<void>(
                  builder: (_) => RegisterScreen(account: account)),
            ),
          ),
      ],
    );
  }
}
