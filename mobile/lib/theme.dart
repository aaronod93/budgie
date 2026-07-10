import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Lil' Budgie palette: dark blue-gray shell, off-white envelope surfaces,
/// orange accent, muted teal secondary. Green/red stay reserved for money
/// semantics (positive/negative amounts).
abstract final class BudgieColors {
  static const ink = Color(0xFF191E2A); // header & background
  static const inkDeep = Color(0xFF131722); // nav bar / darker chrome
  static const inkRaised = Color(0xFF232A3A); // raised surfaces on dark
  static const paper = Color(0xFFE7E6E2); // envelope card
  static const accent = Color(0xFFE3854E); // active / buttons
  static const mist = Color(0xFFA3C0D0); // secondary text on dark

  static const moneyPositive = Color(0xFF66BB6A); // green.shade400
  static const moneyNegative = Color(0xFFE57373); // red.shade300
}

ThemeData budgieTheme() {
  final base = ThemeData(
    colorScheme: ColorScheme.fromSeed(
      seedColor: BudgieColors.accent,
      brightness: Brightness.dark,
      surface: BudgieColors.ink,
    ),
    scaffoldBackgroundColor: BudgieColors.ink,
    useMaterial3: true,
  );

  return base.copyWith(
    textTheme: GoogleFonts.workSansTextTheme(base.textTheme),
    appBarTheme: const AppBarTheme(
      backgroundColor: BudgieColors.ink,
      foregroundColor: BudgieColors.paper,
      elevation: 0,
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: BudgieColors.inkDeep,
      indicatorColor: BudgieColors.accent.withValues(alpha: 0.25),
    ),
    cardTheme: const CardThemeData(color: BudgieColors.inkRaised),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: BudgieColors.accent,
        foregroundColor: BudgieColors.inkDeep,
      ),
    ),
    dividerTheme: DividerThemeData(
      color: BudgieColors.mist.withValues(alpha: 0.15),
    ),
  );
}
