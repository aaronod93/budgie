import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth.dart';
import '../money.dart';
import '../providers.dart';

/// The 10-second capture screen (PLAN.md §7): amount, account, category,
/// payee — save.
class AddTransactionScreen extends ConsumerStatefulWidget {
  const AddTransactionScreen({super.key});

  @override
  ConsumerState<AddTransactionScreen> createState() => _AddTransactionScreenState();
}

class _AddTransactionScreenState extends ConsumerState<AddTransactionScreen> {
  final _amountController = TextEditingController();
  final _payeeController = TextEditingController();
  final _memoController = TextEditingController();

  bool _isOutflow = true;
  bool _busy = false;
  String? _accountUuid;
  String? _categoryValue; // category uuid | 'rta' | null (no category)
  DateTime _date = DateTime.now();

  @override
  void dispose() {
    _amountController.dispose();
    _payeeController.dispose();
    _memoController.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final budget = ref.read(currentBudgetProvider).value;
    final cents = parseMoney(_amountController.text);
    final accountUuid = _accountUuid;

    if (budget == null || accountUuid == null) return;
    if (cents == null || cents <= 0) {
      _snack('Enter an amount.');
      return;
    }

    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post<Map<String, dynamic>>(
        '/budgets/${budget.uuid}/transactions',
        data: {
          'account_id': accountUuid,
          'date': _date.toIso8601String().substring(0, 10),
          'amount': _isOutflow ? -cents : cents,
          if (_payeeController.text.trim().isNotEmpty)
            'payee_name': _payeeController.text.trim(),
          'category_id': _categoryValue == 'rta'
              ? budget.readyToAssignCategoryUuid
              : _categoryValue,
          if (_memoController.text.trim().isNotEmpty)
            'memo': _memoController.text.trim(),
        },
      );

      ref.invalidate(monthProvider);
      ref.invalidate(accountsProvider);
      ref.invalidate(transactionsProvider(accountUuid));

      _amountController.clear();
      _payeeController.clear();
      _memoController.clear();
      setState(() => _date = DateTime.now());
      _snack('Transaction added.');
    } catch (e) {
      _snack(apiErrorMessage(e, 'Could not save the transaction.'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _snack(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    final accounts = ref.watch(accountsProvider).value ?? [];
    final groups = ref.watch(groupsProvider).value ?? [];
    final open = accounts.where((a) => !a.closed).toList();

    if (_accountUuid == null && open.isNotEmpty) {
      _accountUuid = open.first.uuid;
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Add transaction'), centerTitle: true),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          SegmentedButton<bool>(
            segments: const [
              ButtonSegment(value: true, label: Text('Outflow'), icon: Icon(Icons.remove)),
              ButtonSegment(value: false, label: Text('Inflow'), icon: Icon(Icons.add)),
            ],
            selected: {_isOutflow},
            onSelectionChanged: (selection) =>
                setState(() => _isOutflow = selection.first),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _amountController,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            style: Theme.of(context).textTheme.headlineMedium,
            textAlign: TextAlign.center,
            decoration: const InputDecoration(
                hintText: '0.00', prefixText: r'$ ', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 16),
          DropdownButtonFormField<String>(
            initialValue: _accountUuid,
            decoration: const InputDecoration(
                labelText: 'Account', border: OutlineInputBorder()),
            items: [
              for (final account in open)
                DropdownMenuItem(value: account.uuid, child: Text(account.name)),
            ],
            onChanged: (value) => setState(() => _accountUuid = value),
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String?>(
            initialValue: _categoryValue,
            decoration: const InputDecoration(
                labelText: 'Category', border: OutlineInputBorder()),
            items: [
              const DropdownMenuItem<String?>(
                  value: null, child: Text('No category')),
              const DropdownMenuItem<String?>(
                  value: 'rta', child: Text('Inflow: Ready to Assign')),
              for (final group in groups)
                for (final category in group.categories)
                  DropdownMenuItem<String?>(
                      value: category.uuid,
                      child: Text('${group.name} · ${category.name}')),
            ],
            onChanged: (value) => setState(() => _categoryValue = value),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _payeeController,
            decoration: const InputDecoration(
                labelText: 'Payee', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            icon: const Icon(Icons.calendar_today, size: 18),
            label: Text(_date.toIso8601String().substring(0, 10)),
            onPressed: () async {
              final picked = await showDatePicker(
                context: context,
                initialDate: _date,
                firstDate: DateTime(2000),
                lastDate: DateTime(2100),
              );
              if (picked != null) setState(() => _date = picked);
            },
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _memoController,
            decoration: const InputDecoration(
                labelText: 'Memo (optional)', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 20),
          FilledButton(
            onPressed: _busy || open.isEmpty ? null : _save,
            child: Text(_busy ? 'Saving…' : 'Save transaction'),
          ),
        ],
      ),
    );
  }
}
