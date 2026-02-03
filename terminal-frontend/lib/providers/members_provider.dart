import 'package:flutter/foundation.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/services/members_service.dart';

class MembersProvider extends ChangeNotifier {
  final MembersService _service;

  List<MembersCacheData> _members = [];
  MembersCacheData? _selectedMember;
  int? _memberDeckel;
  bool _isLoading = false;
  bool _isSyncing = false;
  String? _lastError;
  Exception? _errorType;

  MembersProvider({required MembersService service}) : _service = service;

  List<MembersCacheData> get members => _members;
  MembersCacheData? get selectedMember => _selectedMember;

  /// Effective balance: synced backend balance + unsynced local transactions
  int? get memberDeckel => _memberDeckel;

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
        _memberDeckel = await _service.getEffectiveBalance(member);
        _lastError = null;
        _errorType = null;
      } else {
        _selectedMember = null;
        _memberDeckel = null;
        _lastError = error;
        _errorType = null;
      }
    } catch (e) {
      _selectedMember = null;
      _memberDeckel = null;
      _lastError = 'Error looking up member: $e';
      _errorType = e as Exception?;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// Recompute effective balance from database
  /// Call after checkout to reflect new unsynced transactions
  Future<void> refreshDeckel() async {
    if (_selectedMember == null) return;
    _memberDeckel = await _service.getEffectiveBalance(_selectedMember!);
    notifyListeners();
  }

  /// Set selected member directly and compute effective balance
  Future<void> setSelectedMember(MembersCacheData member) async {
    _selectedMember = member;
    _memberDeckel = await _service.getEffectiveBalance(member);
    _lastError = null;
    _errorType = null;
    notifyListeners();
  }

  /// Clear selected member
  void clearSelectedMember() {
    _selectedMember = null;
    _memberDeckel = null;
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

  /// Set error state
  void setError(String message) {
    _lastError = message;
    _errorType = null;
    notifyListeners();
  }

  /// Clear error state
  void clearError() {
    _lastError = null;
    _errorType = null;
    notifyListeners();
  }
}
