import 'package:flutter/foundation.dart';
import 'package:clubbar_terminal/database/database.dart';

/// A single successful login, as signalled to the UI — the moment a scanned
/// card actually started a session (`SessionStartResult.started`, ADR-0027).
///
/// [sequence] makes this an *event* rather than a state, for the same reason
/// [TerminalError.sequence] does: the next member logging in right after a
/// logout — or the same member returning later in the evening — must trigger
/// the login animation again, and a value-equality check on the member alone
/// would swallow it.
@immutable
class LoginMoment {
  final MembersCacheData member;
  final int sequence;

  const LoginMoment({required this.member, required this.sequence});

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is LoginMoment &&
          other.member.id == member.id &&
          other.sequence == sequence;

  @override
  int get hashCode => Object.hash(member.id, sequence);

  @override
  String toString() => 'LoginMoment(${member.id}, #$sequence)';
}
