/// Plain models mirroring the API resources (see api/app/Http/Resources).
library;

class Budget {
  const Budget({
    required this.uuid,
    required this.name,
    required this.currency,
    required this.readyToAssignCategoryUuid,
  });

  factory Budget.fromJson(Map<String, dynamic> json) => Budget(
        uuid: json['uuid'] as String,
        name: json['name'] as String,
        currency: json['currency'] as String,
        readyToAssignCategoryUuid: json['ready_to_assign_category_uuid'] as String,
      );

  final String uuid;
  final String name;
  final String currency;
  final String readyToAssignCategoryUuid;
}

class Account {
  const Account({
    required this.uuid,
    required this.name,
    required this.type,
    required this.onBudget,
    required this.closed,
    required this.balance,
    required this.clearedBalance,
  });

  factory Account.fromJson(Map<String, dynamic> json) => Account(
        uuid: json['uuid'] as String,
        name: json['name'] as String,
        type: json['type'] as String,
        onBudget: json['on_budget'] as bool,
        closed: json['closed'] as bool,
        balance: json['balance'] as int,
        clearedBalance: json['cleared_balance'] as int,
      );

  final String uuid;
  final String name;
  final String type;
  final bool onBudget;
  final bool closed;
  final int balance;
  final int clearedBalance;
}

class CategoryRef {
  const CategoryRef({required this.uuid, required this.name});

  factory CategoryRef.fromJson(Map<String, dynamic> json) =>
      CategoryRef(uuid: json['uuid'] as String, name: json['name'] as String);

  final String uuid;
  final String name;
}

class CategoryGroupFull {
  const CategoryGroupFull({required this.uuid, required this.name, required this.categories});

  factory CategoryGroupFull.fromJson(Map<String, dynamic> json) => CategoryGroupFull(
        uuid: json['uuid'] as String,
        name: json['name'] as String,
        categories: (json['categories'] as List<dynamic>)
            .map((c) => CategoryRef.fromJson(c as Map<String, dynamic>))
            .toList(),
      );

  final String uuid;
  final String name;
  final List<CategoryRef> categories;
}

class MonthCategory {
  const MonthCategory({
    required this.uuid,
    required this.name,
    required this.isCreditCardPayment,
    required this.assigned,
    required this.activity,
    required this.available,
  });

  factory MonthCategory.fromJson(Map<String, dynamic> json) => MonthCategory(
        uuid: json['uuid'] as String,
        name: json['name'] as String,
        isCreditCardPayment: json['is_credit_card_payment'] as bool? ?? false,
        assigned: json['assigned'] as int,
        activity: json['activity'] as int,
        available: json['available'] as int,
      );

  final String uuid;
  final String name;
  final bool isCreditCardPayment;
  final int assigned;
  final int activity;
  final int available;
}

class MonthGroup {
  const MonthGroup({required this.uuid, required this.name, required this.categories});

  factory MonthGroup.fromJson(Map<String, dynamic> json) => MonthGroup(
        uuid: json['uuid'] as String,
        name: json['name'] as String,
        categories: (json['categories'] as List<dynamic>)
            .map((c) => MonthCategory.fromJson(c as Map<String, dynamic>))
            .toList(),
      );

  final String uuid;
  final String name;
  final List<MonthCategory> categories;
}

class MonthPayload {
  const MonthPayload({
    required this.month,
    required this.readyToAssign,
    required this.income,
    required this.creditOverspend,
    required this.groups,
  });

  factory MonthPayload.fromJson(Map<String, dynamic> json) => MonthPayload(
        month: json['month'] as String,
        readyToAssign: json['ready_to_assign'] as int,
        income: json['income'] as int,
        creditOverspend: json['credit_overspend'] as int? ?? 0,
        groups: (json['groups'] as List<dynamic>)
            .map((g) => MonthGroup.fromJson(g as Map<String, dynamic>))
            .toList(),
      );

  final String month;
  final int readyToAssign;
  final int income;
  final int creditOverspend;
  final List<MonthGroup> groups;
}

class Txn {
  const Txn({
    required this.uuid,
    required this.date,
    required this.amount,
    required this.cleared,
    this.payeeName,
    this.categoryName,
    this.memo,
    this.transferAccountUuid,
    this.splitCount = 0,
  });

  factory Txn.fromJson(Map<String, dynamic> json) => Txn(
        uuid: json['uuid'] as String,
        date: json['date'] as String,
        amount: json['amount'] as int,
        cleared: json['cleared'] as String,
        payeeName: (json['payee'] as Map<String, dynamic>?)?['name'] as String?,
        categoryName: (json['category'] as Map<String, dynamic>?)?['name'] as String?,
        memo: json['memo'] as String?,
        transferAccountUuid: json['transfer_account_uuid'] as String?,
        splitCount: (json['splits'] as List<dynamic>?)?.length ?? 0,
      );

  final String uuid;
  final String date;
  final int amount;
  final String cleared;
  final String? payeeName;
  final String? categoryName;
  final String? memo;
  final String? transferAccountUuid;
  final int splitCount;

  String get categoryLabel {
    if (splitCount > 0) return 'Split ($splitCount categories)';
    if (categoryName != null) return categoryName!;
    return transferAccountUuid != null ? 'Transfer' : 'Uncategorised';
  }
}
