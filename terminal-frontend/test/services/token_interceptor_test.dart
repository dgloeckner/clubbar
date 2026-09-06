import 'dart:async';

import 'package:chopper/chopper.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:clubbar_terminal/services/token_interceptor.dart';

/// ADR-0054 requirement 10: every terminal-authenticated request says what this
/// terminal is running, and — while an update has failed here — which tag it
/// blacklisted.
///
/// A terminal that blocks a version stops updating until a newer release ships,
/// and nothing on the Pi announces that. These two headers are the only way the
/// club finds out, which is why they ride on ordinary sync traffic rather than
/// on a call somebody has to remember to make.
class _RecordingChain implements Chain<String> {
  @override
  final Request request;

  Request? proceeded;

  _RecordingChain(this.request);

  @override
  FutureOr<Response<String>> proceed(Request request) {
    proceeded = request;
    return Response(http.Response('', 200), '');
  }
}

Request _request() => Request('GET', Uri.parse('/sync/members'), Uri.parse('https://club.example/api'));

void main() {
  group('TokenInterceptor', () {
    test('reports the compiled-in version on an authenticated request', () async {
      final interceptor = TokenInterceptor(appVersion: 'v1.0.7')..token = 'a-token';
      final chain = _RecordingChain(_request());

      await interceptor.intercept(chain);

      expect(chain.proceeded!.headers['Authorization'], 'Bearer a-token');
      expect(chain.proceeded!.headers[TokenInterceptor.versionHeader], 'v1.0.7');
    });

    test('reports the tag whose update failed here', () async {
      final interceptor = TokenInterceptor(appVersion: 'v1.0.6', blockedVersion: 'v1.0.7')
        ..token = 'a-token';
      final chain = _RecordingChain(_request());

      await interceptor.intercept(chain);

      expect(chain.proceeded!.headers[TokenInterceptor.blockedVersionHeader], 'v1.0.7');
    });

    test('sends no blocked header on a terminal that has never failed an update', () async {
      // Almost every terminal, almost all of the time. An always-present header
      // carrying an empty value would have the backend storing "" as a blocked
      // tag and the panel reading it as an alarm.
      final interceptor = TokenInterceptor(appVersion: 'v1.0.7')..token = 'a-token';
      final chain = _RecordingChain(_request());

      await interceptor.intercept(chain);

      expect(chain.proceeded!.headers.containsKey(TokenInterceptor.blockedVersionHeader), isFalse);
    });

    test('sends nothing at all when there is no token', () async {
      // An unauthenticated request is not a terminal reporting in — it is a
      // terminal that has not been configured yet, and the backend has no row
      // to record anything against.
      final interceptor = TokenInterceptor(appVersion: 'v1.0.7', blockedVersion: 'v1.0.8');
      final chain = _RecordingChain(_request());

      await interceptor.intercept(chain);

      expect(chain.proceeded!.headers.containsKey('Authorization'), isFalse);
      expect(chain.proceeded!.headers.containsKey(TokenInterceptor.versionHeader), isFalse);
    });

    test('reports a dev build honestly rather than inventing a version', () async {
      // The backend refuses to store `dev`, which is the point: a club running
      // from git never auto-updates its terminals, and a made-up tag here would
      // hide that on the very page that exists to show it.
      final interceptor = TokenInterceptor(appVersion: 'dev')..token = 'a-token';
      final chain = _RecordingChain(_request());

      await interceptor.intercept(chain);

      expect(chain.proceeded!.headers[TokenInterceptor.versionHeader], 'dev');
    });
  });
}
