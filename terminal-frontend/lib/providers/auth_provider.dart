import 'package:flutter/foundation.dart';

class AuthProvider extends ChangeNotifier {
  String? _token;
  String? _lastError;

  String? get token => _token;
  bool get isAuthenticated => _token != null;
  String? get lastError => _lastError;

  void setToken(String token) {
    _token = token;
    _lastError = null;
    notifyListeners();
  }

  void clearToken() {
    _token = null;
    _lastError = null;
    notifyListeners();
  }

  void setError(String error) {
    _lastError = error;
    notifyListeners();
  }

  void clearError() {
    _lastError = null;
    notifyListeners();
  }
}
