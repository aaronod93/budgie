import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'auth.dart';
import 'models.dart';

/// Dio client bound to the signed-in server + token. Data providers below are
/// plain FutureProviders — pure online, in-memory cache, refreshed by
/// invalidation (pull-to-refresh) per PLAN.md §6.
final apiProvider = Provider<Dio>((ref) {
  final auth = ref.watch(authProvider);
  return Dio(BaseOptions(
    baseUrl: '${auth.serverUrl}/api/v1',
    headers: {
      'Accept': 'application/json',
      if (auth.token != null) 'Authorization': 'Bearer ${auth.token}',
    },
  ));
});

final budgetsProvider = FutureProvider<List<Budget>>((ref) async {
  final response = await ref.watch(apiProvider).get<Map<String, dynamic>>('/budgets');
  return (response.data!['data'] as List<dynamic>)
      .map((b) => Budget.fromJson(b as Map<String, dynamic>))
      .toList();
});

class SelectedBudgetUuid extends Notifier<String?> {
  @override
  String? build() => null;

  void select(String uuid) => state = uuid;
}

final selectedBudgetUuidProvider =
    NotifierProvider<SelectedBudgetUuid, String?>(SelectedBudgetUuid.new);

/// The active budget: the selected one, else the first.
final currentBudgetProvider = FutureProvider<Budget?>((ref) async {
  final budgets = await ref.watch(budgetsProvider.future);
  if (budgets.isEmpty) return null;
  final selected = ref.watch(selectedBudgetUuidProvider);
  return budgets.where((b) => b.uuid == selected).firstOrNull ?? budgets.first;
});

class MonthKey extends Notifier<String> {
  @override
  String build() {
    final now = DateTime.now();
    return '${now.year}-${now.month.toString().padLeft(2, '0')}';
  }

  void shift(int delta) {
    final parts = state.split('-').map(int.parse).toList();
    final date = DateTime(parts[0], parts[1] + delta, 1);
    state = '${date.year}-${date.month.toString().padLeft(2, '0')}';
  }
}

final monthKeyProvider = NotifierProvider<MonthKey, String>(MonthKey.new);

final monthProvider = FutureProvider<MonthPayload?>((ref) async {
  final budget = await ref.watch(currentBudgetProvider.future);
  if (budget == null) return null;
  final key = ref.watch(monthKeyProvider);
  final response = await ref
      .watch(apiProvider)
      .get<Map<String, dynamic>>('/budgets/${budget.uuid}/months/$key');
  return MonthPayload.fromJson(response.data!);
});

final accountsProvider = FutureProvider<List<Account>>((ref) async {
  final budget = await ref.watch(currentBudgetProvider.future);
  if (budget == null) return [];
  final response = await ref
      .watch(apiProvider)
      .get<Map<String, dynamic>>('/budgets/${budget.uuid}/accounts');
  return (response.data!['data'] as List<dynamic>)
      .map((a) => Account.fromJson(a as Map<String, dynamic>))
      .toList();
});

final groupsProvider = FutureProvider<List<CategoryGroupFull>>((ref) async {
  final budget = await ref.watch(currentBudgetProvider.future);
  if (budget == null) return [];
  final response = await ref
      .watch(apiProvider)
      .get<Map<String, dynamic>>('/budgets/${budget.uuid}/category-groups');
  return (response.data!['data'] as List<dynamic>)
      .map((g) => CategoryGroupFull.fromJson(g as Map<String, dynamic>))
      .toList();
});

final transactionsProvider =
    FutureProvider.family<List<Txn>, String>((ref, accountUuid) async {
  final budget = await ref.watch(currentBudgetProvider.future);
  if (budget == null) return [];
  final response = await ref.watch(apiProvider).get<Map<String, dynamic>>(
    '/budgets/${budget.uuid}/transactions',
    queryParameters: {'account_id': accountUuid},
  );
  return (response.data!['data'] as List<dynamic>)
      .map((t) => Txn.fromJson(t as Map<String, dynamic>))
      .toList();
});

