import 'dart:async';
import 'package:chopper/chopper.dart';

/// Mutable Chopper request interceptor that injects a Bearer token.
///
/// The token is set at runtime via [token] after authentication.
/// Both [NetworkService.setAuthToken] and [NetworkService.clearAuthToken]
/// update this field directly — no client rebuild needed.
///
/// In Chopper v8 the [RequestInterceptor] interface was replaced by the
/// chain-based [Interceptor] interface. Tokens are injected by modifying the
/// request before passing it down the chain via [Chain.proceed].
class TokenInterceptor implements Interceptor {
  String? token;

  @override
  FutureOr<Response<BodyType>> intercept<BodyType>(
    Chain<BodyType> chain,
  ) async {
    final request = chain.request;
    if (token == null) {
      return chain.proceed(request);
    }
    // Note: applyHeader() was removed in Chopper v8. Use copyWith() instead.
    final authorizedRequest = request.copyWith(
      headers: {...request.headers, 'Authorization': 'Bearer $token'},
    );
    return chain.proceed(authorizedRequest);
  }
}
