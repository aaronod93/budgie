import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth.dart';
import '../models.dart';
import '../money.dart';
import '../providers.dart';
import '../theme.dart';

class BudgetScreen extends ConsumerWidget {
  const BudgetScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final month = ref.watch(monthProvider);
    final budget = ref.watch(currentBudgetProvider).value;
    final monthKey = ref.watch(monthKeyProvider);

    return Scaffold(
      appBar: AppBar(
        title: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            IconButton(
              icon: const Icon(Icons.chevron_left),
              onPressed: () => ref.read(monthKeyProvider.notifier).shift(-1),
            ),
            Text(_monthLabel(monthKey)),
            IconButton(
              icon: const Icon(Icons.chevron_right),
              onPressed: () => ref.read(monthKeyProvider.notifier).shift(1),
            ),
          ],
        ),
      ),
      body: month.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => _ErrorRetry(
          message: apiErrorMessage(e, 'Could not load the month.'),
          onRetry: () => ref.invalidate(monthProvider),
        ),
        data: (payload) {
          if (payload == null || budget == null) {
            return const Center(
                child: Text('No budget yet — create one on the web app.'));
          }
          return RefreshIndicator(
            onRefresh: () async {
              ref.invalidate(monthProvider);
              await ref.read(monthProvider.future);
            },
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.only(bottom: 24),
              children: [
                _RtaCard(payload: payload, currency: budget.currency),
                if (payload.creditOverspend > 0)
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    child: Card(
                      color: Colors.amber.withValues(alpha: 0.12),
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Text(
                          '${formatMoney(payload.creditOverspend, currency: budget.currency)} of card spending is unfunded and will become debt.',
                          style: TextStyle(color: Colors.amber.shade200),
                        ),
                      ),
                    ),
                  ),
                for (final group in payload.groups) ...[
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
                    child: Text(group.name.toUpperCase(),
                        style: Theme.of(context).textTheme.labelSmall?.copyWith(
                            color: BudgieColors.mist, letterSpacing: 1.2)),
                  ),
                  for (final category in group.categories)
                    _CategoryTile(
                        budget: budget, monthKey: payload.month, category: category),
                ],
              ],
            ),
          );
        },
      ),
    );
  }

  String _monthLabel(String key) {
    const names = [
      'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];
    final parts = key.split('-').map(int.parse).toList();
    return '${names[parts[1] - 1]} ${parts[0]}';
  }
}

class _RtaCard extends StatelessWidget {
  const _RtaCard({required this.payload, required this.currency});

  final MonthPayload payload;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final positive = payload.readyToAssign >= 0;
    return Card(
      margin: const EdgeInsets.all(16),
      color: positive ? BudgieColors.accent : Colors.red.shade700,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            Text(formatMoney(payload.readyToAssign, currency: currency),
                style: Theme.of(context)
                    .textTheme
                    .headlineSmall
                    ?.copyWith(color: positive ? BudgieColors.inkDeep : Colors.white, fontWeight: FontWeight.bold)),
            Text('Ready to Assign', style: TextStyle(color: positive ? BudgieColors.inkDeep.withValues(alpha: 0.7) : Colors.white70)),
          ],
        ),
      ),
    );
  }
}

class _CategoryTile extends ConsumerWidget {
  const _CategoryTile({
    required this.budget,
    required this.monthKey,
    required this.category,
  });

  final Budget budget;
  final String monthKey;
  final MonthCategory category;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final available = category.available;
    final color = available > 0
        ? BudgieColors.moneyPositive
        : available < 0
            ? BudgieColors.moneyNegative
            : BudgieColors.mist;

    return ListTile(
      dense: true,
      title: Text(category.name),
      subtitle: Text(
          'Assigned ${formatMoney(category.assigned, currency: budget.currency)} · '
          'Activity ${formatMoney(category.activity, currency: budget.currency)}'),
      trailing: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(999),
        ),
        child: Text(formatMoney(available, currency: budget.currency),
            style: TextStyle(color: color, fontWeight: FontWeight.w600)),
      ),
      onTap: () => _editAssigned(context, ref),
    );
  }

  Future<void> _editAssigned(BuildContext context, WidgetRef ref) async {
    final controller = TextEditingController(
        text: category.assigned == 0
            ? ''
            : (category.assigned / 100).toStringAsFixed(2));

    final input = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Assign to ${category.name}'),
        content: TextField(
          controller: controller,
          autofocus: true,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(prefixText: r'$ '),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
          FilledButton(
              onPressed: () => Navigator.pop(context, controller.text),
              child: const Text('Assign')),
        ],
      ),
    );

    if (input == null) return;
    final cents = parseMoney(input) ?? 0;
    if (cents == category.assigned) return;

    try {
      await ref.read(apiProvider).post<Map<String, dynamic>>(
        '/budgets/${budget.uuid}/months/$monthKey/categories/${category.uuid}/assign',
        data: {'amount': cents},
      );
      ref.invalidate(monthProvider);
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(apiErrorMessage(e, 'Could not assign.'))));
      }
    }
  }
}

class _ErrorRetry extends StatelessWidget {
  const _ErrorRetry({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(message),
          const SizedBox(height: 8),
          OutlinedButton(onPressed: onRetry, child: const Text('Retry')),
        ],
      ),
    );
  }
}
