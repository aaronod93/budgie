import 'package:intl/intl.dart';

/// All amounts cross the API as integer minor units (cents). These helpers are
/// the only place cents <-> display conversion happens (mirrors web useMoney).
String formatMoney(int cents, {String currency = 'AUD'}) {
  final format = NumberFormat.simpleCurrency(locale: 'en_AU', name: currency);
  return format.format(cents / 100);
}

/// Parse user input like "1,234.50" or "-12" into cents; null if not a number.
int? parseMoney(String input) {
  final cleaned = input.replaceAll(RegExp(r'[^0-9.\-]'), '');
  if (cleaned.isEmpty || cleaned == '-' || cleaned == '.') return null;
  final value = double.tryParse(cleaned);
  return value == null ? null : (value * 100).round();
}
