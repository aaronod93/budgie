import 'package:budgie_mobile/models.dart';
import 'package:budgie_mobile/money.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('money', () {
    test('formats cents as AUD', () {
      expect(formatMoney(123456), r'$1,234.56');
      expect(formatMoney(-500), r'-$5.00');
      expect(formatMoney(0), r'$0.00');
    });

    test('parses user input into cents', () {
      expect(parseMoney('1,234.56'), 123456);
      expect(parseMoney(r'$12'), 1200);
      expect(parseMoney('-5.5'), -550);
      expect(parseMoney(''), null);
      expect(parseMoney('abc'), null);
    });
  });

  group('models', () {
    test('parses a month payload', () {
      final payload = MonthPayload.fromJson({
        'month': '2026-07',
        'ready_to_assign': 150000,
        'income': 200000,
        'credit_overspend': 0,
        'groups': [
          {
            'uuid': 'g1',
            'name': 'Everyday',
            'categories': [
              {
                'uuid': 'c1',
                'name': 'Groceries',
                'is_credit_card_payment': false,
                'assigned': 50000,
                'activity': -12345,
                'available': 37655,
              },
            ],
          },
        ],
      });

      expect(payload.readyToAssign, 150000);
      expect(payload.groups.single.categories.single.available, 37655);
    });

    test('labels transactions by category, split, or transfer', () {
      Txn txn({Map<String, dynamic>? category, String? transfer, List<dynamic>? splits}) =>
          Txn.fromJson({
            'uuid': 't1',
            'date': '2026-07-01',
            'amount': -100,
            'cleared': 'uncleared',
            'payee': null,
            'category': category,
            'transfer_account_uuid': transfer,
            'splits': splits ?? [],
          });

      expect(txn(category: {'uuid': 'c', 'name': 'Groceries'}).categoryLabel, 'Groceries');
      expect(txn(transfer: 'a1').categoryLabel, 'Transfer');
      expect(
        txn(splits: [
          {'uuid': '1', 'amount': -50, 'category_uuid': null, 'memo': null},
          {'uuid': '2', 'amount': -50, 'category_uuid': null, 'memo': null},
        ]).categoryLabel,
        'Split (2 categories)',
      );
      expect(txn().categoryLabel, 'Uncategorised');
    });
  });
}
