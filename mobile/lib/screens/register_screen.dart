import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth.dart';
import '../models.dart';
import '../money.dart';
import '../providers.dart';

class RegisterScreen extends ConsumerWidget {
  const RegisterScreen({super.key, required this.account});

  final Account account;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final transactions = ref.watch(transactionsProvider(account.uuid));
    final budget = ref.watch(currentBudgetProvider).value;
    final currency = budget?.currency ?? 'AUD';

    return Scaffold(
      appBar: AppBar(
        title: Column(
          children: [
            Text(account.name),
            Text(formatMoney(account.balance, currency: currency),
                style: Theme.of(context).textTheme.bodySmall),
          ],
        ),
        centerTitle: true,
      ),
      body: transactions.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(child: Text(apiErrorMessage(e))),
        data: (list) => RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(transactionsProvider(account.uuid));
            ref.invalidate(accountsProvider);
            await ref.read(transactionsProvider(account.uuid).future);
          },
          child: list.isEmpty
              ? ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  children: const [
                    SizedBox(height: 120),
                    Center(child: Text('No transactions yet.')),
                  ],
                )
              : ListView.separated(
                  physics: const AlwaysScrollableScrollPhysics(),
                  itemCount: list.length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (context, index) {
                    final txn = list[index];
                    return ListTile(
                      dense: true,
                      leading: Icon(
                        txn.cleared == 'reconciled'
                            ? Icons.lock_outline
                            : txn.cleared == 'cleared'
                                ? Icons.check_circle
                                : Icons.radio_button_unchecked,
                        size: 18,
                        color: txn.cleared == 'uncleared'
                            ? Colors.grey.shade400
                            : Colors.green.shade700,
                      ),
                      title: Text(txn.payeeName ?? '—'),
                      subtitle: Text('${txn.date} · ${txn.categoryLabel}'),
                      trailing: Text(
                        formatMoney(txn.amount, currency: currency),
                        style: TextStyle(
                          fontWeight: FontWeight.w600,
                          color: txn.amount < 0
                              ? Colors.grey.shade900
                              : Colors.green.shade800,
                        ),
                      ),
                    );
                  },
                ),
        ),
      ),
    );
  }
}
