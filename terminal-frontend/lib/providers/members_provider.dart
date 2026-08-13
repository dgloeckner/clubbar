import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/services/members_service.dart';
import 'package:clubbar_terminal/providers/error_signal.dart';
import 'package:clubbar_terminal/providers/locale_provider.dart';

class MembersProvider extends ChangeNotifier with ErrorSignal {
  final MembersService _service;
  final LocaleProvider? _localeProvider;

  static const _uuid = Uuid();

  List<MembersCacheData> _members = [];
  MembersCacheData? _selectedMember;
  String? _sessionId;
  int? _memberDeckel;
  bool _isLoading = false;
  bool _isSyncing = false;

  MembersProvider({
    required MembersService service,
    LocaleProvider? localeProvider,
  })  : _service = service,
        _localeProvider = localeProvider;

  List<MembersCacheData> get members => _members;
  MembersCacheData? get selectedMember => _selectedMember;
  String? get sessionId => _sessionId;

  /// Effective balance: synced backend balance + unsynced local transactions
  int? get memberDeckel => _memberDeckel;

  bool get isLoading => _isLoading;
  bool get isSyncing => _isSyncing;

  /// Select member by RFID card UID
  Future<void> selectMemberByRfid(String cardUid) async {
    _isLoading = true;
    notifyListeners();

    try {
      final (member, error) = await _service.lookupByRfid(cardUid);

      if (member != null && error == null) {
        _selectedMember = member;
        _sessionId = _uuid.v4();
        _memberDeckel = await _service.getEffectiveBalance(member);
        resetError();

        // Update app locale based on member's preferred language
        _localeProvider?.setLocaleFromMember(member.preferredLanguage);
      } else {
        _selectedMember = null;
        _memberDeckel = null;
        emitError(error ?? TerminalErrorKey.memberLookupFailed);
      }
    } catch (e, stackTrace) {
      _selectedMember = null;
      _memberDeckel = null;
      emitError(TerminalErrorKey.memberLookupFailed,
          cause: e, stackTrace: stackTrace);
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

  /// Start a session for [member]: refresh what they owe, then compute the
  /// effective balance.
  ///
  /// The cached balance is only ever written by a sync response, so it is stale
  /// for every change the terminal did not cause itself — a storno, a
  /// settlement, a sale on another terminal. Login is the moment that number
  /// starts being displayed and enforced against, so it is the moment to ask
  /// the backend for it (#374, ADR-0023). Offline the cached value stands.
  Future<void> setSelectedMember(MembersCacheData member) async {
    final refreshed = await _service.refreshBalance(member.id) ?? member;

    _selectedMember = refreshed;
    _sessionId = _uuid.v4();
    _memberDeckel = await _service.getEffectiveBalance(refreshed);
    resetError();

    // Update app locale based on member's preferred language
    _localeProvider?.setLocaleFromMember(refreshed.preferredLanguage);

    notifyListeners();
  }

  /// Clear selected member
  void clearSelectedMember() {
    _selectedMember = null;
    _sessionId = null;
    _memberDeckel = null;

    // Reset locale to default (German)
    _localeProvider?.resetToDefault();

    notifyListeners();
  }

  /// Refresh members from service
  Future<void> refreshMembers() async {
    _isSyncing = true;
    notifyListeners();

    try {
      final members = await _service.getAllMembers();
      _members = members;
      resetError();
    } catch (e, stackTrace) {
      emitError(TerminalErrorKey.membersRefreshFailed,
          cause: e, stackTrace: stackTrace);
    } finally {
      _isSyncing = false;
      notifyListeners();
    }
  }

  /// Clear cached members
  Future<void> clearCache() async {
    _members = [];
    _selectedMember = null;
    _sessionId = null;
    notifyListeners();
  }

  /// Update the selected member's preferred language.
  /// Persists to local DB and backend (best-effort), then updates app locale.
  Future<void> updateSelectedMemberLanguage(String language) async {
    final member = _selectedMember;
    if (member == null) return;

    await _service.updateLanguage(member.id, language);

    final updated = await _service.findMemberById(member.id);
    _selectedMember = updated ?? member;

    _localeProvider?.setLocaleFromMember(language);
    notifyListeners();
  }

  /// Set error state. Every call is a fresh display event, so an identical
  /// error repeated back-to-back still re-renders.
  void setError(TerminalErrorKey key) {
    emitError(key);
    notifyListeners();
  }
}
