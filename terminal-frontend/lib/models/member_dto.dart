class MemberDTO {
  final String id;
  final String? cardUid;
  final String firstName;
  final String lastName;
  final String preferredLanguage;
  final bool isActive;
  final bool isSepaValid;
  final String updatedAt;

  MemberDTO({
    required this.id,
    this.cardUid,
    required this.firstName,
    required this.lastName,
    required this.preferredLanguage,
    required this.isActive,
    required this.isSepaValid,
    required this.updatedAt,
  });

  factory MemberDTO.fromJson(Map<String, dynamic> json) {
    return MemberDTO(
      id: json['id'] as String,
      cardUid: json['card_uid'] as String?,
      firstName: json['first_name'] as String? ?? '',
      lastName: json['last_name'] as String? ?? '',
      preferredLanguage: json['preferred_language'] as String? ?? 'de',
      isActive: (json['is_active'] as int?) == 1,
      isSepaValid: (json['is_sepa_valid'] as int?) == 1,
      updatedAt: json['updated_at'] as String,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'card_uid': cardUid,
    'first_name': firstName,
    'last_name': lastName,
    'preferred_language': preferredLanguage,
    'is_active': isActive ? 1 : 0,
    'is_sepa_valid': isSepaValid ? 1 : 0,
    'updated_at': updatedAt,
  };
}
