import 'dart:io' show Platform;

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';

enum AuthStatus { initializing, loggedOut, locked, unlocked }

/// Biometrics are a device-level unlock, not a server auth method (PLAN.md §7):
/// full credentials are exchanged once for a Sanctum token kept in secure
/// storage; each app open re-gates access with a biometric prompt.
class AuthState {
  const AuthState({
    required this.status,
    required this.serverUrl,
    this.token,
    this.userName,
    this.userEmail,
    this.biometricsEnabled = true,
  });

  final AuthStatus status;
  final String serverUrl;
  final String? token;
  final String? userName;
  final String? userEmail;
  final bool biometricsEnabled;

  AuthState copyWith({
    AuthStatus? status,
    String? serverUrl,
    String? token,
    String? userName,
    String? userEmail,
    bool? biometricsEnabled,
  }) =>
      AuthState(
        status: status ?? this.status,
        serverUrl: serverUrl ?? this.serverUrl,
        token: token ?? this.token,
        userName: userName ?? this.userName,
        userEmail: userEmail ?? this.userEmail,
        biometricsEnabled: biometricsEnabled ?? this.biometricsEnabled,
      );
}

/// Android emulators reach the host machine via 10.0.2.2.
String defaultServerUrl() =>
    Platform.isAndroid ? 'http://10.0.2.2:8000' : 'http://localhost:8000';

class AuthController extends Notifier<AuthState> {
  static const _storage = FlutterSecureStorage();
  final _localAuth = LocalAuthentication();

  @override
  AuthState build() {
    _restore();
    return AuthState(status: AuthStatus.initializing, serverUrl: defaultServerUrl());
  }

  Future<void> _restore() async {
    final token = await _storage.read(key: 'token');
    final serverUrl = await _storage.read(key: 'server_url') ?? defaultServerUrl();
    final biometrics = await _storage.read(key: 'biometrics') != 'off';
    final userName = await _storage.read(key: 'user_name');
    final userEmail = await _storage.read(key: 'user_email');

    if (token == null) {
      state = AuthState(status: AuthStatus.loggedOut, serverUrl: serverUrl);
      return;
    }

    state = AuthState(
      status: biometrics && await _biometricsAvailable()
          ? AuthStatus.locked
          : AuthStatus.unlocked,
      serverUrl: serverUrl,
      token: token,
      userName: userName,
      userEmail: userEmail,
      biometricsEnabled: biometrics,
    );
  }

  Future<bool> _biometricsAvailable() async {
    try {
      return await _localAuth.isDeviceSupported();
    } catch (_) {
      return false;
    }
  }

  /// Throws [DioException] on failure; a 422 with an `errors.code` key means
  /// the account needs a two-factor code (the login screen reveals the field).
  Future<void> login({
    required String serverUrl,
    required String email,
    required String password,
    String? code,
  }) async {
    final trimmed = serverUrl.replaceAll(RegExp(r'/+$'), '');

    final response = await Dio().post<Map<String, dynamic>>(
      '$trimmed/api/v1/auth/token',
      data: {
        'email': email,
        'password': password,
        'device_name': 'Budgie ${Platform.operatingSystem}',
        if (code != null && code.isNotEmpty) 'code': code,
      },
      options: Options(headers: {'Accept': 'application/json'}),
    );

    final token = response.data!['token'] as String;
    final user = response.data!['user'] as Map<String, dynamic>;

    await _storage.write(key: 'token', value: token);
    await _storage.write(key: 'server_url', value: trimmed);
    await _storage.write(key: 'user_name', value: user['name'] as String);
    await _storage.write(key: 'user_email', value: user['email'] as String);

    state = AuthState(
      status: AuthStatus.unlocked,
      serverUrl: trimmed,
      token: token,
      userName: user['name'] as String,
      userEmail: user['email'] as String,
      biometricsEnabled: state.biometricsEnabled,
    );
  }

  Future<bool> unlock() async {
    try {
      final ok = await _localAuth.authenticate(
        localizedReason: 'Unlock your budget',
        persistAcrossBackgrounding: true,
      );
      if (ok) state = state.copyWith(status: AuthStatus.unlocked);
      return ok;
    } catch (_) {
      // Biometrics unavailable (no enrolment, emulator, ...) — fall back to
      // the login screen rather than locking the user out.
      return false;
    }
  }

  Future<void> setBiometrics(bool enabled) async {
    await _storage.write(key: 'biometrics', value: enabled ? 'on' : 'off');
    state = state.copyWith(biometricsEnabled: enabled);
  }

  Future<void> logout() async {
    final token = state.token;
    final serverUrl = state.serverUrl;

    if (token != null) {
      try {
        await Dio().delete<void>(
          '$serverUrl/api/v1/auth/token',
          options: Options(headers: {
            'Accept': 'application/json',
            'Authorization': 'Bearer $token',
          }),
        );
      } catch (_) {
        // Offline sign-out still wipes the device token.
      }
    }

    for (final key in ['token', 'user_name', 'user_email']) {
      await _storage.delete(key: key);
    }

    state = AuthState(status: AuthStatus.loggedOut, serverUrl: serverUrl);
  }
}

final authProvider = NotifierProvider<AuthController, AuthState>(AuthController.new);

/// Human-readable message from an API error response.
String apiErrorMessage(Object error, [String fallback = 'Something went wrong.']) {
  if (error is DioException) {
    final data = error.response?.data;
    if (data is Map<String, dynamic> && data['message'] is String) {
      return data['message'] as String;
    }
    if (error.type != DioExceptionType.badResponse) {
      return 'Could not reach the server.';
    }
  }
  return fallback;
}

/// True when a 422 asks for a two-factor code.
bool needsTwoFactorCode(Object error) {
  if (error is! DioException) return false;
  final data = error.response?.data;
  if (data is! Map<String, dynamic>) return false;
  final errors = data['errors'];
  return errors is Map<String, dynamic> && errors.containsKey('code');
}
