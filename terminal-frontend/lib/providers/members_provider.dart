import 'package:flutter/foundation.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/services/members_service.dart';

class MembersProvider extends ChangeNotifier {
  final MembersService _service;

  List<MembersCacheData> _members = [];
  MembersCacheData? _selectedMember;
  bool _isLoading = false;
  bool _isSyncing = false;
  String? _lastError;
  Exception? _errorType;

  MembersProvider({required MembersService service}) : _service = service;

  List<MembersCacheData> get members => _members;
  MembersCacheData? get selectedMember => _selectedMember;
  bool get isLoading => _isLoading;
  bool get isSyncing => _isSyncing;
  String? get lastError => _lastError;
  Exception? get errorType => _errorType;

  /// Select member by RFID card UID
  Future<void> selectMemberByRfid(String cardUid) async {
    _isLoading = true;
    notifyListeners();

    try {
      final (member, error) = await _service.lookupByRfid(cardUid);

      if (member != null && error == null) {
        _selectedMember = member;
        _lastError = null;
        _errorType = null;
      } else {
        _selectedMember = null;
        _lastError = error;
        _errorType = null;
      }
    } catch (e) {
      _selectedMember = null;
      _lastError = 'Error looking up member: $e';
      _errorType = e as Exception?;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// Clear selected member
  void clearSelectedMember() {
    _selectedMember = null;
    notifyListeners();
  }

  /// Refresh members from service
  Future<void> refreshMembers() async {
    _isSyncing = true;
    notifyListeners();

    try {
      final members = await _service.getAllMembers();
      _members = members;
      _lastError = null;
      _errorType = null;
    } catch (e) {
      _lastError = 'Failed to refresh members: $e';
      _errorType = e as Exception?;
    } finally {
      _isSyncing = false;
      notifyListeners();
    }
  }

  /// Clear cached members
  Future<void> clearCache() async {
    _members = [];
    _selectedMember = null;
    notifyListeners();
  }
}
