import 'dart:async';
import 'package:chopper/chopper.dart';

import '../config/app_config.dart';

/// Mutable Chopper request interceptor that injects a Bearer token, and says
/// what this terminal is running.
///
/// The token is set at runtime via [token] after authentication.
/// Both [NetworkService.setAuthToken] and [NetworkService.clearAuthToken]
/// update this field directly — no client rebuild needed.
///
/// The version headers ride along here, and not in a second interceptor,
/// because "every terminal-authenticated request" is exactly the set of
/// requests this interceptor already touches (ADR-0054). A terminal that syncs
/// but books nothing still reports; the version belongs to the *terminal*, not
/// to a batch of transactions, which is why it is a header rather than a field
/// in the sync payload.
///
/// The backend treats both as fail-open: it records what it can parse and
/// refuses nothing. That contract is what lets this send them unconditionally.
///
/// In Chopper v8 the [RequestInterceptor] interface was replaced by the
/// chain-based [Interceptor] interface. Tokens are injected by modifying the
/// request before passing it down the chain via [Chain.proceed].
class TokenInterceptor implements Interceptor {
  /// What this terminal is running (ADR-0054).
  static const String versionHeader = 'X-Terminal-Version';

  /// The tag whose update failed here, while one has.
  static const String blockedVersionHeader = 'X-Terminal-Blocked-Version';

  String? token;

  /// The compiled-in `APP_VERSION`, which is `dev` for anything CI did not
  /// build from a release tag.
  ///
  /// This is display and reporting only. It is never the updater's input —
  /// there the `current` symlink is the single source of truth for what is
  /// installed, precisely so that a second copy of the version cannot
  /// contradict the directory it sits in.
  final String appVersion;

  /// Set once at startup from the updater's state file; null on the terminals
  /// that have never failed an update, which is almost all of them.
  String? blockedVersion;

  TokenInterceptor({String? appVersion, this.blockedVersion})
      : appVersion = appVersion ?? AppConfig.version;

  @override
  FutureOr<Response<BodyType>> intercept<BodyType>(
    Chain<BodyType> chain,
  ) async {
    final request = chain.request;
    if (token == null) {
      return chain.proceed(request);
    }
    // Note: applyHeader() was removed in Chopper v8. Use copyWith() instead.
    final blocked = blockedVersion;
    final authorizedRequest = request.copyWith(
      headers: {
        ...request.headers,
        'Authorization': 'Bearer $token',
        versionHeader: appVersion,
        if (blocked != null && blocked.isNotEmpty) blockedVersionHeader: blocked,
      },
    );
    return chain.proceed(authorizedRequest);
  }
}
