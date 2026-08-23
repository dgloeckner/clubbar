// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'database.dart';

// ignore_for_file: type=lint
class $MembersCacheTable extends MembersCache
    with TableInfo<$MembersCacheTable, MembersCacheData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $MembersCacheTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _cardUidMeta = const VerificationMeta(
    'cardUid',
  );
  @override
  late final GeneratedColumn<String> cardUid = GeneratedColumn<String>(
    'card_uid',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways('UNIQUE'),
  );
  static const VerificationMeta _firstNameMeta = const VerificationMeta(
    'firstName',
  );
  @override
  late final GeneratedColumn<String> firstName = GeneratedColumn<String>(
    'first_name',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _lastNameMeta = const VerificationMeta(
    'lastName',
  );
  @override
  late final GeneratedColumn<String> lastName = GeneratedColumn<String>(
    'last_name',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _dateOfBirthMeta = const VerificationMeta(
    'dateOfBirth',
  );
  @override
  late final GeneratedColumn<String> dateOfBirth = GeneratedColumn<String>(
    'date_of_birth',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _preferredLanguageMeta = const VerificationMeta(
    'preferredLanguage',
  );
  @override
  late final GeneratedColumn<String> preferredLanguage =
      GeneratedColumn<String>(
        'preferred_language',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      );
  static const VerificationMeta _isActiveMeta = const VerificationMeta(
    'isActive',
  );
  @override
  late final GeneratedColumn<int> isActive = GeneratedColumn<int>(
    'is_active',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: Constant(1),
  );
  static const VerificationMeta _isSepaValidMeta = const VerificationMeta(
    'isSepaValid',
  );
  @override
  late final GeneratedColumn<int> isSepaValid = GeneratedColumn<int>(
    'is_sepa_valid',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _balanceCentsMeta = const VerificationMeta(
    'balanceCents',
  );
  @override
  late final GeneratedColumn<int> balanceCents = GeneratedColumn<int>(
    'balance_cents',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<String> updatedAt = GeneratedColumn<String>(
    'updated_at',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _deletedAtMeta = const VerificationMeta(
    'deletedAt',
  );
  @override
  late final GeneratedColumn<String> deletedAt = GeneratedColumn<String>(
    'deleted_at',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    cardUid,
    firstName,
    lastName,
    dateOfBirth,
    preferredLanguage,
    isActive,
    isSepaValid,
    balanceCents,
    updatedAt,
    deletedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'members_cache';
  @override
  VerificationContext validateIntegrity(
    Insertable<MembersCacheData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('card_uid')) {
      context.handle(
        _cardUidMeta,
        cardUid.isAcceptableOrUnknown(data['card_uid']!, _cardUidMeta),
      );
    }
    if (data.containsKey('first_name')) {
      context.handle(
        _firstNameMeta,
        firstName.isAcceptableOrUnknown(data['first_name']!, _firstNameMeta),
      );
    }
    if (data.containsKey('last_name')) {
      context.handle(
        _lastNameMeta,
        lastName.isAcceptableOrUnknown(data['last_name']!, _lastNameMeta),
      );
    }
    if (data.containsKey('date_of_birth')) {
      context.handle(
        _dateOfBirthMeta,
        dateOfBirth.isAcceptableOrUnknown(
          data['date_of_birth']!,
          _dateOfBirthMeta,
        ),
      );
    }
    if (data.containsKey('preferred_language')) {
      context.handle(
        _preferredLanguageMeta,
        preferredLanguage.isAcceptableOrUnknown(
          data['preferred_language']!,
          _preferredLanguageMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_preferredLanguageMeta);
    }
    if (data.containsKey('is_active')) {
      context.handle(
        _isActiveMeta,
        isActive.isAcceptableOrUnknown(data['is_active']!, _isActiveMeta),
      );
    }
    if (data.containsKey('is_sepa_valid')) {
      context.handle(
        _isSepaValidMeta,
        isSepaValid.isAcceptableOrUnknown(
          data['is_sepa_valid']!,
          _isSepaValidMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_isSepaValidMeta);
    }
    if (data.containsKey('balance_cents')) {
      context.handle(
        _balanceCentsMeta,
        balanceCents.isAcceptableOrUnknown(
          data['balance_cents']!,
          _balanceCentsMeta,
        ),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    } else if (isInserting) {
      context.missing(_updatedAtMeta);
    }
    if (data.containsKey('deleted_at')) {
      context.handle(
        _deletedAtMeta,
        deletedAt.isAcceptableOrUnknown(data['deleted_at']!, _deletedAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  MembersCacheData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return MembersCacheData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      cardUid: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}card_uid'],
      ),
      firstName: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}first_name'],
      ),
      lastName: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}last_name'],
      ),
      dateOfBirth: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}date_of_birth'],
      ),
      preferredLanguage: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}preferred_language'],
      )!,
      isActive: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}is_active'],
      )!,
      isSepaValid: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}is_sepa_valid'],
      )!,
      balanceCents: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}balance_cents'],
      )!,
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}updated_at'],
      )!,
      deletedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}deleted_at'],
      ),
    );
  }

  @override
  $MembersCacheTable createAlias(String alias) {
    return $MembersCacheTable(attachedDatabase, alias);
  }
}

class MembersCacheData extends DataClass
    implements Insertable<MembersCacheData> {
  final String id;
  final String? cardUid;
  final String? firstName;
  final String? lastName;

  /// The member's date of birth as `YYYY-MM-DD`, for the Jugendschutz check
  /// (ADR-0045).
  ///
  /// The **raw date**, deliberately, and never an age in years: age changes on
  /// a day the server cannot predict a sync for, so a cached number is wrong
  /// from the member's next birthday until this terminal next reaches the
  /// server — which may be weeks. The age is computed here, at checkout, from
  /// this device's clock.
  ///
  /// Null means the member was **anonymized**, never "unknown": the field is
  /// required when a member is created, so there is no third state and no
  /// fail-open branch. An erasure arrives on the ordinary delta sync with this
  /// nulled, which is what takes the date back off every kiosk.
  ///
  /// Never render it, and never derive a rendered age from it (rule 6) — the
  /// screen is read by whoever is standing at the bar.
  final String? dateOfBirth;
  final String preferredLanguage;
  final int isActive;
  final int isSepaValid;
  final int balanceCents;
  final String updatedAt;

  /// Server tombstone (ISO 8601). Set means the member was anonymized (GDPR
  /// erasure); their card must scan as unknown.
  ///
  /// The row itself is never removed — see the same field on `ProductsCache`.
  /// `transactions_local.member_id` references it, and deleting the row used to
  /// throw `FOREIGN KEY constraint failed` out of the first step of the sync
  /// cycle, wedging every later step with it.
  final String? deletedAt;
  const MembersCacheData({
    required this.id,
    this.cardUid,
    this.firstName,
    this.lastName,
    this.dateOfBirth,
    required this.preferredLanguage,
    required this.isActive,
    required this.isSepaValid,
    required this.balanceCents,
    required this.updatedAt,
    this.deletedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    if (!nullToAbsent || cardUid != null) {
      map['card_uid'] = Variable<String>(cardUid);
    }
    if (!nullToAbsent || firstName != null) {
      map['first_name'] = Variable<String>(firstName);
    }
    if (!nullToAbsent || lastName != null) {
      map['last_name'] = Variable<String>(lastName);
    }
    if (!nullToAbsent || dateOfBirth != null) {
      map['date_of_birth'] = Variable<String>(dateOfBirth);
    }
    map['preferred_language'] = Variable<String>(preferredLanguage);
    map['is_active'] = Variable<int>(isActive);
    map['is_sepa_valid'] = Variable<int>(isSepaValid);
    map['balance_cents'] = Variable<int>(balanceCents);
    map['updated_at'] = Variable<String>(updatedAt);
    if (!nullToAbsent || deletedAt != null) {
      map['deleted_at'] = Variable<String>(deletedAt);
    }
    return map;
  }

  MembersCacheCompanion toCompanion(bool nullToAbsent) {
    return MembersCacheCompanion(
      id: Value(id),
      cardUid: cardUid == null && nullToAbsent
          ? const Value.absent()
          : Value(cardUid),
      firstName: firstName == null && nullToAbsent
          ? const Value.absent()
          : Value(firstName),
      lastName: lastName == null && nullToAbsent
          ? const Value.absent()
          : Value(lastName),
      dateOfBirth: dateOfBirth == null && nullToAbsent
          ? const Value.absent()
          : Value(dateOfBirth),
      preferredLanguage: Value(preferredLanguage),
      isActive: Value(isActive),
      isSepaValid: Value(isSepaValid),
      balanceCents: Value(balanceCents),
      updatedAt: Value(updatedAt),
      deletedAt: deletedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(deletedAt),
    );
  }

  factory MembersCacheData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return MembersCacheData(
      id: serializer.fromJson<String>(json['id']),
      cardUid: serializer.fromJson<String?>(json['cardUid']),
      firstName: serializer.fromJson<String?>(json['firstName']),
      lastName: serializer.fromJson<String?>(json['lastName']),
      dateOfBirth: serializer.fromJson<String?>(json['dateOfBirth']),
      preferredLanguage: serializer.fromJson<String>(json['preferredLanguage']),
      isActive: serializer.fromJson<int>(json['isActive']),
      isSepaValid: serializer.fromJson<int>(json['isSepaValid']),
      balanceCents: serializer.fromJson<int>(json['balanceCents']),
      updatedAt: serializer.fromJson<String>(json['updatedAt']),
      deletedAt: serializer.fromJson<String?>(json['deletedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'cardUid': serializer.toJson<String?>(cardUid),
      'firstName': serializer.toJson<String?>(firstName),
      'lastName': serializer.toJson<String?>(lastName),
      'dateOfBirth': serializer.toJson<String?>(dateOfBirth),
      'preferredLanguage': serializer.toJson<String>(preferredLanguage),
      'isActive': serializer.toJson<int>(isActive),
      'isSepaValid': serializer.toJson<int>(isSepaValid),
      'balanceCents': serializer.toJson<int>(balanceCents),
      'updatedAt': serializer.toJson<String>(updatedAt),
      'deletedAt': serializer.toJson<String?>(deletedAt),
    };
  }

  MembersCacheData copyWith({
    String? id,
    Value<String?> cardUid = const Value.absent(),
    Value<String?> firstName = const Value.absent(),
    Value<String?> lastName = const Value.absent(),
    Value<String?> dateOfBirth = const Value.absent(),
    String? preferredLanguage,
    int? isActive,
    int? isSepaValid,
    int? balanceCents,
    String? updatedAt,
    Value<String?> deletedAt = const Value.absent(),
  }) => MembersCacheData(
    id: id ?? this.id,
    cardUid: cardUid.present ? cardUid.value : this.cardUid,
    firstName: firstName.present ? firstName.value : this.firstName,
    lastName: lastName.present ? lastName.value : this.lastName,
    dateOfBirth: dateOfBirth.present ? dateOfBirth.value : this.dateOfBirth,
    preferredLanguage: preferredLanguage ?? this.preferredLanguage,
    isActive: isActive ?? this.isActive,
    isSepaValid: isSepaValid ?? this.isSepaValid,
    balanceCents: balanceCents ?? this.balanceCents,
    updatedAt: updatedAt ?? this.updatedAt,
    deletedAt: deletedAt.present ? deletedAt.value : this.deletedAt,
  );
  MembersCacheData copyWithCompanion(MembersCacheCompanion data) {
    return MembersCacheData(
      id: data.id.present ? data.id.value : this.id,
      cardUid: data.cardUid.present ? data.cardUid.value : this.cardUid,
      firstName: data.firstName.present ? data.firstName.value : this.firstName,
      lastName: data.lastName.present ? data.lastName.value : this.lastName,
      dateOfBirth: data.dateOfBirth.present
          ? data.dateOfBirth.value
          : this.dateOfBirth,
      preferredLanguage: data.preferredLanguage.present
          ? data.preferredLanguage.value
          : this.preferredLanguage,
      isActive: data.isActive.present ? data.isActive.value : this.isActive,
      isSepaValid: data.isSepaValid.present
          ? data.isSepaValid.value
          : this.isSepaValid,
      balanceCents: data.balanceCents.present
          ? data.balanceCents.value
          : this.balanceCents,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
      deletedAt: data.deletedAt.present ? data.deletedAt.value : this.deletedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('MembersCacheData(')
          ..write('id: $id, ')
          ..write('cardUid: $cardUid, ')
          ..write('firstName: $firstName, ')
          ..write('lastName: $lastName, ')
          ..write('dateOfBirth: $dateOfBirth, ')
          ..write('preferredLanguage: $preferredLanguage, ')
          ..write('isActive: $isActive, ')
          ..write('isSepaValid: $isSepaValid, ')
          ..write('balanceCents: $balanceCents, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('deletedAt: $deletedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    cardUid,
    firstName,
    lastName,
    dateOfBirth,
    preferredLanguage,
    isActive,
    isSepaValid,
    balanceCents,
    updatedAt,
    deletedAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is MembersCacheData &&
          other.id == this.id &&
          other.cardUid == this.cardUid &&
          other.firstName == this.firstName &&
          other.lastName == this.lastName &&
          other.dateOfBirth == this.dateOfBirth &&
          other.preferredLanguage == this.preferredLanguage &&
          other.isActive == this.isActive &&
          other.isSepaValid == this.isSepaValid &&
          other.balanceCents == this.balanceCents &&
          other.updatedAt == this.updatedAt &&
          other.deletedAt == this.deletedAt);
}

class MembersCacheCompanion extends UpdateCompanion<MembersCacheData> {
  final Value<String> id;
  final Value<String?> cardUid;
  final Value<String?> firstName;
  final Value<String?> lastName;
  final Value<String?> dateOfBirth;
  final Value<String> preferredLanguage;
  final Value<int> isActive;
  final Value<int> isSepaValid;
  final Value<int> balanceCents;
  final Value<String> updatedAt;
  final Value<String?> deletedAt;
  final Value<int> rowid;
  const MembersCacheCompanion({
    this.id = const Value.absent(),
    this.cardUid = const Value.absent(),
    this.firstName = const Value.absent(),
    this.lastName = const Value.absent(),
    this.dateOfBirth = const Value.absent(),
    this.preferredLanguage = const Value.absent(),
    this.isActive = const Value.absent(),
    this.isSepaValid = const Value.absent(),
    this.balanceCents = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.deletedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  MembersCacheCompanion.insert({
    required String id,
    this.cardUid = const Value.absent(),
    this.firstName = const Value.absent(),
    this.lastName = const Value.absent(),
    this.dateOfBirth = const Value.absent(),
    required String preferredLanguage,
    this.isActive = const Value.absent(),
    required int isSepaValid,
    this.balanceCents = const Value.absent(),
    required String updatedAt,
    this.deletedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       preferredLanguage = Value(preferredLanguage),
       isSepaValid = Value(isSepaValid),
       updatedAt = Value(updatedAt);
  static Insertable<MembersCacheData> custom({
    Expression<String>? id,
    Expression<String>? cardUid,
    Expression<String>? firstName,
    Expression<String>? lastName,
    Expression<String>? dateOfBirth,
    Expression<String>? preferredLanguage,
    Expression<int>? isActive,
    Expression<int>? isSepaValid,
    Expression<int>? balanceCents,
    Expression<String>? updatedAt,
    Expression<String>? deletedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (cardUid != null) 'card_uid': cardUid,
      if (firstName != null) 'first_name': firstName,
      if (lastName != null) 'last_name': lastName,
      if (dateOfBirth != null) 'date_of_birth': dateOfBirth,
      if (preferredLanguage != null) 'preferred_language': preferredLanguage,
      if (isActive != null) 'is_active': isActive,
      if (isSepaValid != null) 'is_sepa_valid': isSepaValid,
      if (balanceCents != null) 'balance_cents': balanceCents,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (deletedAt != null) 'deleted_at': deletedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  MembersCacheCompanion copyWith({
    Value<String>? id,
    Value<String?>? cardUid,
    Value<String?>? firstName,
    Value<String?>? lastName,
    Value<String?>? dateOfBirth,
    Value<String>? preferredLanguage,
    Value<int>? isActive,
    Value<int>? isSepaValid,
    Value<int>? balanceCents,
    Value<String>? updatedAt,
    Value<String?>? deletedAt,
    Value<int>? rowid,
  }) {
    return MembersCacheCompanion(
      id: id ?? this.id,
      cardUid: cardUid ?? this.cardUid,
      firstName: firstName ?? this.firstName,
      lastName: lastName ?? this.lastName,
      dateOfBirth: dateOfBirth ?? this.dateOfBirth,
      preferredLanguage: preferredLanguage ?? this.preferredLanguage,
      isActive: isActive ?? this.isActive,
      isSepaValid: isSepaValid ?? this.isSepaValid,
      balanceCents: balanceCents ?? this.balanceCents,
      updatedAt: updatedAt ?? this.updatedAt,
      deletedAt: deletedAt ?? this.deletedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (cardUid.present) {
      map['card_uid'] = Variable<String>(cardUid.value);
    }
    if (firstName.present) {
      map['first_name'] = Variable<String>(firstName.value);
    }
    if (lastName.present) {
      map['last_name'] = Variable<String>(lastName.value);
    }
    if (dateOfBirth.present) {
      map['date_of_birth'] = Variable<String>(dateOfBirth.value);
    }
    if (preferredLanguage.present) {
      map['preferred_language'] = Variable<String>(preferredLanguage.value);
    }
    if (isActive.present) {
      map['is_active'] = Variable<int>(isActive.value);
    }
    if (isSepaValid.present) {
      map['is_sepa_valid'] = Variable<int>(isSepaValid.value);
    }
    if (balanceCents.present) {
      map['balance_cents'] = Variable<int>(balanceCents.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<String>(updatedAt.value);
    }
    if (deletedAt.present) {
      map['deleted_at'] = Variable<String>(deletedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('MembersCacheCompanion(')
          ..write('id: $id, ')
          ..write('cardUid: $cardUid, ')
          ..write('firstName: $firstName, ')
          ..write('lastName: $lastName, ')
          ..write('dateOfBirth: $dateOfBirth, ')
          ..write('preferredLanguage: $preferredLanguage, ')
          ..write('isActive: $isActive, ')
          ..write('isSepaValid: $isSepaValid, ')
          ..write('balanceCents: $balanceCents, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('deletedAt: $deletedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $CategoriesCacheTable extends CategoriesCache
    with TableInfo<$CategoriesCacheTable, CategoriesCacheData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $CategoriesCacheTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _namesMeta = const VerificationMeta('names');
  @override
  late final GeneratedColumn<String> names = GeneratedColumn<String>(
    'names',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _isActiveMeta = const VerificationMeta(
    'isActive',
  );
  @override
  late final GeneratedColumn<int> isActive = GeneratedColumn<int>(
    'is_active',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: Constant(1),
  );
  static const VerificationMeta _iconNameMeta = const VerificationMeta(
    'iconName',
  );
  @override
  late final GeneratedColumn<String> iconName = GeneratedColumn<String>(
    'icon_name',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<String> updatedAt = GeneratedColumn<String>(
    'updated_at',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _deletedAtMeta = const VerificationMeta(
    'deletedAt',
  );
  @override
  late final GeneratedColumn<String> deletedAt = GeneratedColumn<String>(
    'deleted_at',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    names,
    isActive,
    iconName,
    updatedAt,
    deletedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'categories_cache';
  @override
  VerificationContext validateIntegrity(
    Insertable<CategoriesCacheData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('names')) {
      context.handle(
        _namesMeta,
        names.isAcceptableOrUnknown(data['names']!, _namesMeta),
      );
    } else if (isInserting) {
      context.missing(_namesMeta);
    }
    if (data.containsKey('is_active')) {
      context.handle(
        _isActiveMeta,
        isActive.isAcceptableOrUnknown(data['is_active']!, _isActiveMeta),
      );
    }
    if (data.containsKey('icon_name')) {
      context.handle(
        _iconNameMeta,
        iconName.isAcceptableOrUnknown(data['icon_name']!, _iconNameMeta),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    } else if (isInserting) {
      context.missing(_updatedAtMeta);
    }
    if (data.containsKey('deleted_at')) {
      context.handle(
        _deletedAtMeta,
        deletedAt.isAcceptableOrUnknown(data['deleted_at']!, _deletedAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  CategoriesCacheData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return CategoriesCacheData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      names: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}names'],
      )!,
      isActive: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}is_active'],
      )!,
      iconName: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}icon_name'],
      ),
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}updated_at'],
      )!,
      deletedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}deleted_at'],
      ),
    );
  }

  @override
  $CategoriesCacheTable createAlias(String alias) {
    return $CategoriesCacheTable(attachedDatabase, alias);
  }
}

class CategoriesCacheData extends DataClass
    implements Insertable<CategoriesCacheData> {
  final String id;
  final String names;
  final int isActive;
  final String? iconName;
  final String updatedAt;

  /// Server tombstone (ISO 8601). Set means the category was deleted in the admin
  /// panel; it and all its products are hidden from the purchase UI.
  ///
  /// The row itself is never removed — see the same field on `ProductsCache`.
  /// Products reference the category, and those products are in turn referenced
  /// by local transactions.
  final String? deletedAt;
  const CategoriesCacheData({
    required this.id,
    required this.names,
    required this.isActive,
    this.iconName,
    required this.updatedAt,
    this.deletedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['names'] = Variable<String>(names);
    map['is_active'] = Variable<int>(isActive);
    if (!nullToAbsent || iconName != null) {
      map['icon_name'] = Variable<String>(iconName);
    }
    map['updated_at'] = Variable<String>(updatedAt);
    if (!nullToAbsent || deletedAt != null) {
      map['deleted_at'] = Variable<String>(deletedAt);
    }
    return map;
  }

  CategoriesCacheCompanion toCompanion(bool nullToAbsent) {
    return CategoriesCacheCompanion(
      id: Value(id),
      names: Value(names),
      isActive: Value(isActive),
      iconName: iconName == null && nullToAbsent
          ? const Value.absent()
          : Value(iconName),
      updatedAt: Value(updatedAt),
      deletedAt: deletedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(deletedAt),
    );
  }

  factory CategoriesCacheData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return CategoriesCacheData(
      id: serializer.fromJson<String>(json['id']),
      names: serializer.fromJson<String>(json['names']),
      isActive: serializer.fromJson<int>(json['isActive']),
      iconName: serializer.fromJson<String?>(json['iconName']),
      updatedAt: serializer.fromJson<String>(json['updatedAt']),
      deletedAt: serializer.fromJson<String?>(json['deletedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'names': serializer.toJson<String>(names),
      'isActive': serializer.toJson<int>(isActive),
      'iconName': serializer.toJson<String?>(iconName),
      'updatedAt': serializer.toJson<String>(updatedAt),
      'deletedAt': serializer.toJson<String?>(deletedAt),
    };
  }

  CategoriesCacheData copyWith({
    String? id,
    String? names,
    int? isActive,
    Value<String?> iconName = const Value.absent(),
    String? updatedAt,
    Value<String?> deletedAt = const Value.absent(),
  }) => CategoriesCacheData(
    id: id ?? this.id,
    names: names ?? this.names,
    isActive: isActive ?? this.isActive,
    iconName: iconName.present ? iconName.value : this.iconName,
    updatedAt: updatedAt ?? this.updatedAt,
    deletedAt: deletedAt.present ? deletedAt.value : this.deletedAt,
  );
  CategoriesCacheData copyWithCompanion(CategoriesCacheCompanion data) {
    return CategoriesCacheData(
      id: data.id.present ? data.id.value : this.id,
      names: data.names.present ? data.names.value : this.names,
      isActive: data.isActive.present ? data.isActive.value : this.isActive,
      iconName: data.iconName.present ? data.iconName.value : this.iconName,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
      deletedAt: data.deletedAt.present ? data.deletedAt.value : this.deletedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('CategoriesCacheData(')
          ..write('id: $id, ')
          ..write('names: $names, ')
          ..write('isActive: $isActive, ')
          ..write('iconName: $iconName, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('deletedAt: $deletedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode =>
      Object.hash(id, names, isActive, iconName, updatedAt, deletedAt);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is CategoriesCacheData &&
          other.id == this.id &&
          other.names == this.names &&
          other.isActive == this.isActive &&
          other.iconName == this.iconName &&
          other.updatedAt == this.updatedAt &&
          other.deletedAt == this.deletedAt);
}

class CategoriesCacheCompanion extends UpdateCompanion<CategoriesCacheData> {
  final Value<String> id;
  final Value<String> names;
  final Value<int> isActive;
  final Value<String?> iconName;
  final Value<String> updatedAt;
  final Value<String?> deletedAt;
  final Value<int> rowid;
  const CategoriesCacheCompanion({
    this.id = const Value.absent(),
    this.names = const Value.absent(),
    this.isActive = const Value.absent(),
    this.iconName = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.deletedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  CategoriesCacheCompanion.insert({
    required String id,
    required String names,
    this.isActive = const Value.absent(),
    this.iconName = const Value.absent(),
    required String updatedAt,
    this.deletedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       names = Value(names),
       updatedAt = Value(updatedAt);
  static Insertable<CategoriesCacheData> custom({
    Expression<String>? id,
    Expression<String>? names,
    Expression<int>? isActive,
    Expression<String>? iconName,
    Expression<String>? updatedAt,
    Expression<String>? deletedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (names != null) 'names': names,
      if (isActive != null) 'is_active': isActive,
      if (iconName != null) 'icon_name': iconName,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (deletedAt != null) 'deleted_at': deletedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  CategoriesCacheCompanion copyWith({
    Value<String>? id,
    Value<String>? names,
    Value<int>? isActive,
    Value<String?>? iconName,
    Value<String>? updatedAt,
    Value<String?>? deletedAt,
    Value<int>? rowid,
  }) {
    return CategoriesCacheCompanion(
      id: id ?? this.id,
      names: names ?? this.names,
      isActive: isActive ?? this.isActive,
      iconName: iconName ?? this.iconName,
      updatedAt: updatedAt ?? this.updatedAt,
      deletedAt: deletedAt ?? this.deletedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (names.present) {
      map['names'] = Variable<String>(names.value);
    }
    if (isActive.present) {
      map['is_active'] = Variable<int>(isActive.value);
    }
    if (iconName.present) {
      map['icon_name'] = Variable<String>(iconName.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<String>(updatedAt.value);
    }
    if (deletedAt.present) {
      map['deleted_at'] = Variable<String>(deletedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('CategoriesCacheCompanion(')
          ..write('id: $id, ')
          ..write('names: $names, ')
          ..write('isActive: $isActive, ')
          ..write('iconName: $iconName, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('deletedAt: $deletedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $ProductsCacheTable extends ProductsCache
    with TableInfo<$ProductsCacheTable, ProductsCacheData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $ProductsCacheTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _categoryIdMeta = const VerificationMeta(
    'categoryId',
  );
  @override
  late final GeneratedColumn<String> categoryId = GeneratedColumn<String>(
    'category_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'REFERENCES categories_cache (id)',
    ),
  );
  static const VerificationMeta _namesMeta = const VerificationMeta('names');
  @override
  late final GeneratedColumn<String> names = GeneratedColumn<String>(
    'names',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _descriptionsMeta = const VerificationMeta(
    'descriptions',
  );
  @override
  late final GeneratedColumn<String> descriptions = GeneratedColumn<String>(
    'descriptions',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _priceCentsMeta = const VerificationMeta(
    'priceCents',
  );
  @override
  late final GeneratedColumn<int> priceCents = GeneratedColumn<int>(
    'price_cents',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _isActiveMeta = const VerificationMeta(
    'isActive',
  );
  @override
  late final GeneratedColumn<int> isActive = GeneratedColumn<int>(
    'is_active',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: Constant(1),
  );
  static const VerificationMeta _requiresDispenserMeta = const VerificationMeta(
    'requiresDispenser',
  );
  @override
  late final GeneratedColumn<int> requiresDispenser = GeneratedColumn<int>(
    'requires_dispenser',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: Constant(0),
  );
  static const VerificationMeta _minAgeMeta = const VerificationMeta('minAge');
  @override
  late final GeneratedColumn<int> minAge = GeneratedColumn<int>(
    'min_age',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _iconNameMeta = const VerificationMeta(
    'iconName',
  );
  @override
  late final GeneratedColumn<String> iconName = GeneratedColumn<String>(
    'icon_name',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<String> updatedAt = GeneratedColumn<String>(
    'updated_at',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _deletedAtMeta = const VerificationMeta(
    'deletedAt',
  );
  @override
  late final GeneratedColumn<String> deletedAt = GeneratedColumn<String>(
    'deleted_at',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    categoryId,
    names,
    descriptions,
    priceCents,
    isActive,
    requiresDispenser,
    minAge,
    iconName,
    updatedAt,
    deletedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'products_cache';
  @override
  VerificationContext validateIntegrity(
    Insertable<ProductsCacheData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('category_id')) {
      context.handle(
        _categoryIdMeta,
        categoryId.isAcceptableOrUnknown(data['category_id']!, _categoryIdMeta),
      );
    } else if (isInserting) {
      context.missing(_categoryIdMeta);
    }
    if (data.containsKey('names')) {
      context.handle(
        _namesMeta,
        names.isAcceptableOrUnknown(data['names']!, _namesMeta),
      );
    } else if (isInserting) {
      context.missing(_namesMeta);
    }
    if (data.containsKey('descriptions')) {
      context.handle(
        _descriptionsMeta,
        descriptions.isAcceptableOrUnknown(
          data['descriptions']!,
          _descriptionsMeta,
        ),
      );
    }
    if (data.containsKey('price_cents')) {
      context.handle(
        _priceCentsMeta,
        priceCents.isAcceptableOrUnknown(data['price_cents']!, _priceCentsMeta),
      );
    } else if (isInserting) {
      context.missing(_priceCentsMeta);
    }
    if (data.containsKey('is_active')) {
      context.handle(
        _isActiveMeta,
        isActive.isAcceptableOrUnknown(data['is_active']!, _isActiveMeta),
      );
    }
    if (data.containsKey('requires_dispenser')) {
      context.handle(
        _requiresDispenserMeta,
        requiresDispenser.isAcceptableOrUnknown(
          data['requires_dispenser']!,
          _requiresDispenserMeta,
        ),
      );
    }
    if (data.containsKey('min_age')) {
      context.handle(
        _minAgeMeta,
        minAge.isAcceptableOrUnknown(data['min_age']!, _minAgeMeta),
      );
    }
    if (data.containsKey('icon_name')) {
      context.handle(
        _iconNameMeta,
        iconName.isAcceptableOrUnknown(data['icon_name']!, _iconNameMeta),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    } else if (isInserting) {
      context.missing(_updatedAtMeta);
    }
    if (data.containsKey('deleted_at')) {
      context.handle(
        _deletedAtMeta,
        deletedAt.isAcceptableOrUnknown(data['deleted_at']!, _deletedAtMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  ProductsCacheData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return ProductsCacheData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      categoryId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}category_id'],
      )!,
      names: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}names'],
      )!,
      descriptions: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}descriptions'],
      ),
      priceCents: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}price_cents'],
      )!,
      isActive: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}is_active'],
      )!,
      requiresDispenser: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}requires_dispenser'],
      )!,
      minAge: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}min_age'],
      ),
      iconName: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}icon_name'],
      ),
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}updated_at'],
      )!,
      deletedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}deleted_at'],
      ),
    );
  }

  @override
  $ProductsCacheTable createAlias(String alias) {
    return $ProductsCacheTable(attachedDatabase, alias);
  }
}

class ProductsCacheData extends DataClass
    implements Insertable<ProductsCacheData> {
  final String id;
  final String categoryId;
  final String names;
  final String? descriptions;
  final int priceCents;
  final int isActive;
  final int requiresDispenser;

  /// Minimum legal age to buy this product, or null for unrestricted
  /// (ADR-0045). Compared against the age computed from the member's own
  /// `date_of_birth` at checkout, offline.
  ///
  /// Null is the ordinary state of most of a drinks list. A free integer
  /// rather than a `{16, 18}` enum: JuSchG's two thresholds are German law,
  /// and a club running this elsewhere sets its own numbers.
  final int? minAge;
  final String? iconName;
  final String updatedAt;

  /// Server tombstone (ISO 8601). Set means the product was deleted in the admin
  /// panel and must be hidden from the purchase UI.
  ///
  /// The row itself is never removed. `transactions_local.product_id` references
  /// it under `PRAGMA foreign_keys = ON` with no `ON DELETE` clause, and synced
  /// transactions are retained indefinitely — so a physical delete would be
  /// refused by SQLite and abort the whole sync cycle. Keeping the row also lets
  /// transaction history and the quarantine banner still name the product.
  final String? deletedAt;
  const ProductsCacheData({
    required this.id,
    required this.categoryId,
    required this.names,
    this.descriptions,
    required this.priceCents,
    required this.isActive,
    required this.requiresDispenser,
    this.minAge,
    this.iconName,
    required this.updatedAt,
    this.deletedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['category_id'] = Variable<String>(categoryId);
    map['names'] = Variable<String>(names);
    if (!nullToAbsent || descriptions != null) {
      map['descriptions'] = Variable<String>(descriptions);
    }
    map['price_cents'] = Variable<int>(priceCents);
    map['is_active'] = Variable<int>(isActive);
    map['requires_dispenser'] = Variable<int>(requiresDispenser);
    if (!nullToAbsent || minAge != null) {
      map['min_age'] = Variable<int>(minAge);
    }
    if (!nullToAbsent || iconName != null) {
      map['icon_name'] = Variable<String>(iconName);
    }
    map['updated_at'] = Variable<String>(updatedAt);
    if (!nullToAbsent || deletedAt != null) {
      map['deleted_at'] = Variable<String>(deletedAt);
    }
    return map;
  }

  ProductsCacheCompanion toCompanion(bool nullToAbsent) {
    return ProductsCacheCompanion(
      id: Value(id),
      categoryId: Value(categoryId),
      names: Value(names),
      descriptions: descriptions == null && nullToAbsent
          ? const Value.absent()
          : Value(descriptions),
      priceCents: Value(priceCents),
      isActive: Value(isActive),
      requiresDispenser: Value(requiresDispenser),
      minAge: minAge == null && nullToAbsent
          ? const Value.absent()
          : Value(minAge),
      iconName: iconName == null && nullToAbsent
          ? const Value.absent()
          : Value(iconName),
      updatedAt: Value(updatedAt),
      deletedAt: deletedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(deletedAt),
    );
  }

  factory ProductsCacheData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return ProductsCacheData(
      id: serializer.fromJson<String>(json['id']),
      categoryId: serializer.fromJson<String>(json['categoryId']),
      names: serializer.fromJson<String>(json['names']),
      descriptions: serializer.fromJson<String?>(json['descriptions']),
      priceCents: serializer.fromJson<int>(json['priceCents']),
      isActive: serializer.fromJson<int>(json['isActive']),
      requiresDispenser: serializer.fromJson<int>(json['requiresDispenser']),
      minAge: serializer.fromJson<int?>(json['minAge']),
      iconName: serializer.fromJson<String?>(json['iconName']),
      updatedAt: serializer.fromJson<String>(json['updatedAt']),
      deletedAt: serializer.fromJson<String?>(json['deletedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'categoryId': serializer.toJson<String>(categoryId),
      'names': serializer.toJson<String>(names),
      'descriptions': serializer.toJson<String?>(descriptions),
      'priceCents': serializer.toJson<int>(priceCents),
      'isActive': serializer.toJson<int>(isActive),
      'requiresDispenser': serializer.toJson<int>(requiresDispenser),
      'minAge': serializer.toJson<int?>(minAge),
      'iconName': serializer.toJson<String?>(iconName),
      'updatedAt': serializer.toJson<String>(updatedAt),
      'deletedAt': serializer.toJson<String?>(deletedAt),
    };
  }

  ProductsCacheData copyWith({
    String? id,
    String? categoryId,
    String? names,
    Value<String?> descriptions = const Value.absent(),
    int? priceCents,
    int? isActive,
    int? requiresDispenser,
    Value<int?> minAge = const Value.absent(),
    Value<String?> iconName = const Value.absent(),
    String? updatedAt,
    Value<String?> deletedAt = const Value.absent(),
  }) => ProductsCacheData(
    id: id ?? this.id,
    categoryId: categoryId ?? this.categoryId,
    names: names ?? this.names,
    descriptions: descriptions.present ? descriptions.value : this.descriptions,
    priceCents: priceCents ?? this.priceCents,
    isActive: isActive ?? this.isActive,
    requiresDispenser: requiresDispenser ?? this.requiresDispenser,
    minAge: minAge.present ? minAge.value : this.minAge,
    iconName: iconName.present ? iconName.value : this.iconName,
    updatedAt: updatedAt ?? this.updatedAt,
    deletedAt: deletedAt.present ? deletedAt.value : this.deletedAt,
  );
  ProductsCacheData copyWithCompanion(ProductsCacheCompanion data) {
    return ProductsCacheData(
      id: data.id.present ? data.id.value : this.id,
      categoryId: data.categoryId.present
          ? data.categoryId.value
          : this.categoryId,
      names: data.names.present ? data.names.value : this.names,
      descriptions: data.descriptions.present
          ? data.descriptions.value
          : this.descriptions,
      priceCents: data.priceCents.present
          ? data.priceCents.value
          : this.priceCents,
      isActive: data.isActive.present ? data.isActive.value : this.isActive,
      requiresDispenser: data.requiresDispenser.present
          ? data.requiresDispenser.value
          : this.requiresDispenser,
      minAge: data.minAge.present ? data.minAge.value : this.minAge,
      iconName: data.iconName.present ? data.iconName.value : this.iconName,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
      deletedAt: data.deletedAt.present ? data.deletedAt.value : this.deletedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('ProductsCacheData(')
          ..write('id: $id, ')
          ..write('categoryId: $categoryId, ')
          ..write('names: $names, ')
          ..write('descriptions: $descriptions, ')
          ..write('priceCents: $priceCents, ')
          ..write('isActive: $isActive, ')
          ..write('requiresDispenser: $requiresDispenser, ')
          ..write('minAge: $minAge, ')
          ..write('iconName: $iconName, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('deletedAt: $deletedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    categoryId,
    names,
    descriptions,
    priceCents,
    isActive,
    requiresDispenser,
    minAge,
    iconName,
    updatedAt,
    deletedAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is ProductsCacheData &&
          other.id == this.id &&
          other.categoryId == this.categoryId &&
          other.names == this.names &&
          other.descriptions == this.descriptions &&
          other.priceCents == this.priceCents &&
          other.isActive == this.isActive &&
          other.requiresDispenser == this.requiresDispenser &&
          other.minAge == this.minAge &&
          other.iconName == this.iconName &&
          other.updatedAt == this.updatedAt &&
          other.deletedAt == this.deletedAt);
}

class ProductsCacheCompanion extends UpdateCompanion<ProductsCacheData> {
  final Value<String> id;
  final Value<String> categoryId;
  final Value<String> names;
  final Value<String?> descriptions;
  final Value<int> priceCents;
  final Value<int> isActive;
  final Value<int> requiresDispenser;
  final Value<int?> minAge;
  final Value<String?> iconName;
  final Value<String> updatedAt;
  final Value<String?> deletedAt;
  final Value<int> rowid;
  const ProductsCacheCompanion({
    this.id = const Value.absent(),
    this.categoryId = const Value.absent(),
    this.names = const Value.absent(),
    this.descriptions = const Value.absent(),
    this.priceCents = const Value.absent(),
    this.isActive = const Value.absent(),
    this.requiresDispenser = const Value.absent(),
    this.minAge = const Value.absent(),
    this.iconName = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.deletedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  ProductsCacheCompanion.insert({
    required String id,
    required String categoryId,
    required String names,
    this.descriptions = const Value.absent(),
    required int priceCents,
    this.isActive = const Value.absent(),
    this.requiresDispenser = const Value.absent(),
    this.minAge = const Value.absent(),
    this.iconName = const Value.absent(),
    required String updatedAt,
    this.deletedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       categoryId = Value(categoryId),
       names = Value(names),
       priceCents = Value(priceCents),
       updatedAt = Value(updatedAt);
  static Insertable<ProductsCacheData> custom({
    Expression<String>? id,
    Expression<String>? categoryId,
    Expression<String>? names,
    Expression<String>? descriptions,
    Expression<int>? priceCents,
    Expression<int>? isActive,
    Expression<int>? requiresDispenser,
    Expression<int>? minAge,
    Expression<String>? iconName,
    Expression<String>? updatedAt,
    Expression<String>? deletedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (categoryId != null) 'category_id': categoryId,
      if (names != null) 'names': names,
      if (descriptions != null) 'descriptions': descriptions,
      if (priceCents != null) 'price_cents': priceCents,
      if (isActive != null) 'is_active': isActive,
      if (requiresDispenser != null) 'requires_dispenser': requiresDispenser,
      if (minAge != null) 'min_age': minAge,
      if (iconName != null) 'icon_name': iconName,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (deletedAt != null) 'deleted_at': deletedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  ProductsCacheCompanion copyWith({
    Value<String>? id,
    Value<String>? categoryId,
    Value<String>? names,
    Value<String?>? descriptions,
    Value<int>? priceCents,
    Value<int>? isActive,
    Value<int>? requiresDispenser,
    Value<int?>? minAge,
    Value<String?>? iconName,
    Value<String>? updatedAt,
    Value<String?>? deletedAt,
    Value<int>? rowid,
  }) {
    return ProductsCacheCompanion(
      id: id ?? this.id,
      categoryId: categoryId ?? this.categoryId,
      names: names ?? this.names,
      descriptions: descriptions ?? this.descriptions,
      priceCents: priceCents ?? this.priceCents,
      isActive: isActive ?? this.isActive,
      requiresDispenser: requiresDispenser ?? this.requiresDispenser,
      minAge: minAge ?? this.minAge,
      iconName: iconName ?? this.iconName,
      updatedAt: updatedAt ?? this.updatedAt,
      deletedAt: deletedAt ?? this.deletedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (categoryId.present) {
      map['category_id'] = Variable<String>(categoryId.value);
    }
    if (names.present) {
      map['names'] = Variable<String>(names.value);
    }
    if (descriptions.present) {
      map['descriptions'] = Variable<String>(descriptions.value);
    }
    if (priceCents.present) {
      map['price_cents'] = Variable<int>(priceCents.value);
    }
    if (isActive.present) {
      map['is_active'] = Variable<int>(isActive.value);
    }
    if (requiresDispenser.present) {
      map['requires_dispenser'] = Variable<int>(requiresDispenser.value);
    }
    if (minAge.present) {
      map['min_age'] = Variable<int>(minAge.value);
    }
    if (iconName.present) {
      map['icon_name'] = Variable<String>(iconName.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<String>(updatedAt.value);
    }
    if (deletedAt.present) {
      map['deleted_at'] = Variable<String>(deletedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('ProductsCacheCompanion(')
          ..write('id: $id, ')
          ..write('categoryId: $categoryId, ')
          ..write('names: $names, ')
          ..write('descriptions: $descriptions, ')
          ..write('priceCents: $priceCents, ')
          ..write('isActive: $isActive, ')
          ..write('requiresDispenser: $requiresDispenser, ')
          ..write('minAge: $minAge, ')
          ..write('iconName: $iconName, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('deletedAt: $deletedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $TransactionsLocalTable extends TransactionsLocal
    with TableInfo<$TransactionsLocalTable, TransactionsLocalData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $TransactionsLocalTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _memberIdMeta = const VerificationMeta(
    'memberId',
  );
  @override
  late final GeneratedColumn<String> memberId = GeneratedColumn<String>(
    'member_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'REFERENCES members_cache (id)',
    ),
  );
  static const VerificationMeta _productIdMeta = const VerificationMeta(
    'productId',
  );
  @override
  late final GeneratedColumn<String> productId = GeneratedColumn<String>(
    'product_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'REFERENCES products_cache (id)',
    ),
  );
  static const VerificationMeta _amountCentsMeta = const VerificationMeta(
    'amountCents',
  );
  @override
  late final GeneratedColumn<int> amountCents = GeneratedColumn<int>(
    'amount_cents',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _transactionTypeMeta = const VerificationMeta(
    'transactionType',
  );
  @override
  late final GeneratedColumn<String> transactionType = GeneratedColumn<String>(
    'transaction_type',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _notesMeta = const VerificationMeta('notes');
  @override
  late final GeneratedColumn<String> notes = GeneratedColumn<String>(
    'notes',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _createdAtMeta = const VerificationMeta(
    'createdAt',
  );
  @override
  late final GeneratedColumn<String> createdAt = GeneratedColumn<String>(
    'created_at',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _syncedMeta = const VerificationMeta('synced');
  @override
  late final GeneratedColumn<int> synced = GeneratedColumn<int>(
    'synced',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: Constant(0),
  );
  static const VerificationMeta _dispenserTxIdMeta = const VerificationMeta(
    'dispenserTxId',
  );
  @override
  late final GeneratedColumn<String> dispenserTxId = GeneratedColumn<String>(
    'dispenser_tx_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _dispenserRequestedMeta =
      const VerificationMeta('dispenserRequested');
  @override
  late final GeneratedColumn<int> dispenserRequested = GeneratedColumn<int>(
    'dispenser_requested',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _dispenserActualMeta = const VerificationMeta(
    'dispenserActual',
  );
  @override
  late final GeneratedColumn<int> dispenserActual = GeneratedColumn<int>(
    'dispenser_actual',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _sessionIdMeta = const VerificationMeta(
    'sessionId',
  );
  @override
  late final GeneratedColumn<String> sessionId = GeneratedColumn<String>(
    'session_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _unitPriceCentsMeta = const VerificationMeta(
    'unitPriceCents',
  );
  @override
  late final GeneratedColumn<int> unitPriceCents = GeneratedColumn<int>(
    'unit_price_cents',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _quarantinedAtMeta = const VerificationMeta(
    'quarantinedAt',
  );
  @override
  late final GeneratedColumn<String> quarantinedAt = GeneratedColumn<String>(
    'quarantined_at',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _quarantineReasonMeta = const VerificationMeta(
    'quarantineReason',
  );
  @override
  late final GeneratedColumn<String> quarantineReason = GeneratedColumn<String>(
    'quarantine_reason',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    memberId,
    productId,
    amountCents,
    transactionType,
    notes,
    createdAt,
    synced,
    dispenserTxId,
    dispenserRequested,
    dispenserActual,
    sessionId,
    unitPriceCents,
    quarantinedAt,
    quarantineReason,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'transactions_local';
  @override
  VerificationContext validateIntegrity(
    Insertable<TransactionsLocalData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('member_id')) {
      context.handle(
        _memberIdMeta,
        memberId.isAcceptableOrUnknown(data['member_id']!, _memberIdMeta),
      );
    } else if (isInserting) {
      context.missing(_memberIdMeta);
    }
    if (data.containsKey('product_id')) {
      context.handle(
        _productIdMeta,
        productId.isAcceptableOrUnknown(data['product_id']!, _productIdMeta),
      );
    }
    if (data.containsKey('amount_cents')) {
      context.handle(
        _amountCentsMeta,
        amountCents.isAcceptableOrUnknown(
          data['amount_cents']!,
          _amountCentsMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_amountCentsMeta);
    }
    if (data.containsKey('transaction_type')) {
      context.handle(
        _transactionTypeMeta,
        transactionType.isAcceptableOrUnknown(
          data['transaction_type']!,
          _transactionTypeMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_transactionTypeMeta);
    }
    if (data.containsKey('notes')) {
      context.handle(
        _notesMeta,
        notes.isAcceptableOrUnknown(data['notes']!, _notesMeta),
      );
    }
    if (data.containsKey('created_at')) {
      context.handle(
        _createdAtMeta,
        createdAt.isAcceptableOrUnknown(data['created_at']!, _createdAtMeta),
      );
    } else if (isInserting) {
      context.missing(_createdAtMeta);
    }
    if (data.containsKey('synced')) {
      context.handle(
        _syncedMeta,
        synced.isAcceptableOrUnknown(data['synced']!, _syncedMeta),
      );
    }
    if (data.containsKey('dispenser_tx_id')) {
      context.handle(
        _dispenserTxIdMeta,
        dispenserTxId.isAcceptableOrUnknown(
          data['dispenser_tx_id']!,
          _dispenserTxIdMeta,
        ),
      );
    }
    if (data.containsKey('dispenser_requested')) {
      context.handle(
        _dispenserRequestedMeta,
        dispenserRequested.isAcceptableOrUnknown(
          data['dispenser_requested']!,
          _dispenserRequestedMeta,
        ),
      );
    }
    if (data.containsKey('dispenser_actual')) {
      context.handle(
        _dispenserActualMeta,
        dispenserActual.isAcceptableOrUnknown(
          data['dispenser_actual']!,
          _dispenserActualMeta,
        ),
      );
    }
    if (data.containsKey('session_id')) {
      context.handle(
        _sessionIdMeta,
        sessionId.isAcceptableOrUnknown(data['session_id']!, _sessionIdMeta),
      );
    }
    if (data.containsKey('unit_price_cents')) {
      context.handle(
        _unitPriceCentsMeta,
        unitPriceCents.isAcceptableOrUnknown(
          data['unit_price_cents']!,
          _unitPriceCentsMeta,
        ),
      );
    }
    if (data.containsKey('quarantined_at')) {
      context.handle(
        _quarantinedAtMeta,
        quarantinedAt.isAcceptableOrUnknown(
          data['quarantined_at']!,
          _quarantinedAtMeta,
        ),
      );
    }
    if (data.containsKey('quarantine_reason')) {
      context.handle(
        _quarantineReasonMeta,
        quarantineReason.isAcceptableOrUnknown(
          data['quarantine_reason']!,
          _quarantineReasonMeta,
        ),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  TransactionsLocalData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return TransactionsLocalData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      memberId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}member_id'],
      )!,
      productId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}product_id'],
      ),
      amountCents: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}amount_cents'],
      )!,
      transactionType: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}transaction_type'],
      )!,
      notes: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}notes'],
      ),
      createdAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}created_at'],
      )!,
      synced: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}synced'],
      )!,
      dispenserTxId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}dispenser_tx_id'],
      ),
      dispenserRequested: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}dispenser_requested'],
      ),
      dispenserActual: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}dispenser_actual'],
      ),
      sessionId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}session_id'],
      ),
      unitPriceCents: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}unit_price_cents'],
      ),
      quarantinedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}quarantined_at'],
      ),
      quarantineReason: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}quarantine_reason'],
      ),
    );
  }

  @override
  $TransactionsLocalTable createAlias(String alias) {
    return $TransactionsLocalTable(attachedDatabase, alias);
  }
}

class TransactionsLocalData extends DataClass
    implements Insertable<TransactionsLocalData> {
  final String id;
  final String memberId;
  final String? productId;
  final int amountCents;
  final String transactionType;
  final String? notes;
  final String createdAt;
  final int synced;
  final String? dispenserTxId;
  final int? dispenserRequested;
  final int? dispenserActual;
  final String? sessionId;
  final int? unitPriceCents;

  /// When the backend permanently refused this row (ISO 8601). Set means the
  /// row has left the sync queue for good: resubmitting it cannot help, so it
  /// is kept for staff to report rather than looping forever (issue #152).
  final String? quarantinedAt;

  /// Machine-readable rejection code the backend gave (`not_found`,
  /// `unstorable`), or a local reason the row can never be sent.
  final String? quarantineReason;
  const TransactionsLocalData({
    required this.id,
    required this.memberId,
    this.productId,
    required this.amountCents,
    required this.transactionType,
    this.notes,
    required this.createdAt,
    required this.synced,
    this.dispenserTxId,
    this.dispenserRequested,
    this.dispenserActual,
    this.sessionId,
    this.unitPriceCents,
    this.quarantinedAt,
    this.quarantineReason,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['member_id'] = Variable<String>(memberId);
    if (!nullToAbsent || productId != null) {
      map['product_id'] = Variable<String>(productId);
    }
    map['amount_cents'] = Variable<int>(amountCents);
    map['transaction_type'] = Variable<String>(transactionType);
    if (!nullToAbsent || notes != null) {
      map['notes'] = Variable<String>(notes);
    }
    map['created_at'] = Variable<String>(createdAt);
    map['synced'] = Variable<int>(synced);
    if (!nullToAbsent || dispenserTxId != null) {
      map['dispenser_tx_id'] = Variable<String>(dispenserTxId);
    }
    if (!nullToAbsent || dispenserRequested != null) {
      map['dispenser_requested'] = Variable<int>(dispenserRequested);
    }
    if (!nullToAbsent || dispenserActual != null) {
      map['dispenser_actual'] = Variable<int>(dispenserActual);
    }
    if (!nullToAbsent || sessionId != null) {
      map['session_id'] = Variable<String>(sessionId);
    }
    if (!nullToAbsent || unitPriceCents != null) {
      map['unit_price_cents'] = Variable<int>(unitPriceCents);
    }
    if (!nullToAbsent || quarantinedAt != null) {
      map['quarantined_at'] = Variable<String>(quarantinedAt);
    }
    if (!nullToAbsent || quarantineReason != null) {
      map['quarantine_reason'] = Variable<String>(quarantineReason);
    }
    return map;
  }

  TransactionsLocalCompanion toCompanion(bool nullToAbsent) {
    return TransactionsLocalCompanion(
      id: Value(id),
      memberId: Value(memberId),
      productId: productId == null && nullToAbsent
          ? const Value.absent()
          : Value(productId),
      amountCents: Value(amountCents),
      transactionType: Value(transactionType),
      notes: notes == null && nullToAbsent
          ? const Value.absent()
          : Value(notes),
      createdAt: Value(createdAt),
      synced: Value(synced),
      dispenserTxId: dispenserTxId == null && nullToAbsent
          ? const Value.absent()
          : Value(dispenserTxId),
      dispenserRequested: dispenserRequested == null && nullToAbsent
          ? const Value.absent()
          : Value(dispenserRequested),
      dispenserActual: dispenserActual == null && nullToAbsent
          ? const Value.absent()
          : Value(dispenserActual),
      sessionId: sessionId == null && nullToAbsent
          ? const Value.absent()
          : Value(sessionId),
      unitPriceCents: unitPriceCents == null && nullToAbsent
          ? const Value.absent()
          : Value(unitPriceCents),
      quarantinedAt: quarantinedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(quarantinedAt),
      quarantineReason: quarantineReason == null && nullToAbsent
          ? const Value.absent()
          : Value(quarantineReason),
    );
  }

  factory TransactionsLocalData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return TransactionsLocalData(
      id: serializer.fromJson<String>(json['id']),
      memberId: serializer.fromJson<String>(json['memberId']),
      productId: serializer.fromJson<String?>(json['productId']),
      amountCents: serializer.fromJson<int>(json['amountCents']),
      transactionType: serializer.fromJson<String>(json['transactionType']),
      notes: serializer.fromJson<String?>(json['notes']),
      createdAt: serializer.fromJson<String>(json['createdAt']),
      synced: serializer.fromJson<int>(json['synced']),
      dispenserTxId: serializer.fromJson<String?>(json['dispenserTxId']),
      dispenserRequested: serializer.fromJson<int?>(json['dispenserRequested']),
      dispenserActual: serializer.fromJson<int?>(json['dispenserActual']),
      sessionId: serializer.fromJson<String?>(json['sessionId']),
      unitPriceCents: serializer.fromJson<int?>(json['unitPriceCents']),
      quarantinedAt: serializer.fromJson<String?>(json['quarantinedAt']),
      quarantineReason: serializer.fromJson<String?>(json['quarantineReason']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'memberId': serializer.toJson<String>(memberId),
      'productId': serializer.toJson<String?>(productId),
      'amountCents': serializer.toJson<int>(amountCents),
      'transactionType': serializer.toJson<String>(transactionType),
      'notes': serializer.toJson<String?>(notes),
      'createdAt': serializer.toJson<String>(createdAt),
      'synced': serializer.toJson<int>(synced),
      'dispenserTxId': serializer.toJson<String?>(dispenserTxId),
      'dispenserRequested': serializer.toJson<int?>(dispenserRequested),
      'dispenserActual': serializer.toJson<int?>(dispenserActual),
      'sessionId': serializer.toJson<String?>(sessionId),
      'unitPriceCents': serializer.toJson<int?>(unitPriceCents),
      'quarantinedAt': serializer.toJson<String?>(quarantinedAt),
      'quarantineReason': serializer.toJson<String?>(quarantineReason),
    };
  }

  TransactionsLocalData copyWith({
    String? id,
    String? memberId,
    Value<String?> productId = const Value.absent(),
    int? amountCents,
    String? transactionType,
    Value<String?> notes = const Value.absent(),
    String? createdAt,
    int? synced,
    Value<String?> dispenserTxId = const Value.absent(),
    Value<int?> dispenserRequested = const Value.absent(),
    Value<int?> dispenserActual = const Value.absent(),
    Value<String?> sessionId = const Value.absent(),
    Value<int?> unitPriceCents = const Value.absent(),
    Value<String?> quarantinedAt = const Value.absent(),
    Value<String?> quarantineReason = const Value.absent(),
  }) => TransactionsLocalData(
    id: id ?? this.id,
    memberId: memberId ?? this.memberId,
    productId: productId.present ? productId.value : this.productId,
    amountCents: amountCents ?? this.amountCents,
    transactionType: transactionType ?? this.transactionType,
    notes: notes.present ? notes.value : this.notes,
    createdAt: createdAt ?? this.createdAt,
    synced: synced ?? this.synced,
    dispenserTxId: dispenserTxId.present
        ? dispenserTxId.value
        : this.dispenserTxId,
    dispenserRequested: dispenserRequested.present
        ? dispenserRequested.value
        : this.dispenserRequested,
    dispenserActual: dispenserActual.present
        ? dispenserActual.value
        : this.dispenserActual,
    sessionId: sessionId.present ? sessionId.value : this.sessionId,
    unitPriceCents: unitPriceCents.present
        ? unitPriceCents.value
        : this.unitPriceCents,
    quarantinedAt: quarantinedAt.present
        ? quarantinedAt.value
        : this.quarantinedAt,
    quarantineReason: quarantineReason.present
        ? quarantineReason.value
        : this.quarantineReason,
  );
  TransactionsLocalData copyWithCompanion(TransactionsLocalCompanion data) {
    return TransactionsLocalData(
      id: data.id.present ? data.id.value : this.id,
      memberId: data.memberId.present ? data.memberId.value : this.memberId,
      productId: data.productId.present ? data.productId.value : this.productId,
      amountCents: data.amountCents.present
          ? data.amountCents.value
          : this.amountCents,
      transactionType: data.transactionType.present
          ? data.transactionType.value
          : this.transactionType,
      notes: data.notes.present ? data.notes.value : this.notes,
      createdAt: data.createdAt.present ? data.createdAt.value : this.createdAt,
      synced: data.synced.present ? data.synced.value : this.synced,
      dispenserTxId: data.dispenserTxId.present
          ? data.dispenserTxId.value
          : this.dispenserTxId,
      dispenserRequested: data.dispenserRequested.present
          ? data.dispenserRequested.value
          : this.dispenserRequested,
      dispenserActual: data.dispenserActual.present
          ? data.dispenserActual.value
          : this.dispenserActual,
      sessionId: data.sessionId.present ? data.sessionId.value : this.sessionId,
      unitPriceCents: data.unitPriceCents.present
          ? data.unitPriceCents.value
          : this.unitPriceCents,
      quarantinedAt: data.quarantinedAt.present
          ? data.quarantinedAt.value
          : this.quarantinedAt,
      quarantineReason: data.quarantineReason.present
          ? data.quarantineReason.value
          : this.quarantineReason,
    );
  }

  @override
  String toString() {
    return (StringBuffer('TransactionsLocalData(')
          ..write('id: $id, ')
          ..write('memberId: $memberId, ')
          ..write('productId: $productId, ')
          ..write('amountCents: $amountCents, ')
          ..write('transactionType: $transactionType, ')
          ..write('notes: $notes, ')
          ..write('createdAt: $createdAt, ')
          ..write('synced: $synced, ')
          ..write('dispenserTxId: $dispenserTxId, ')
          ..write('dispenserRequested: $dispenserRequested, ')
          ..write('dispenserActual: $dispenserActual, ')
          ..write('sessionId: $sessionId, ')
          ..write('unitPriceCents: $unitPriceCents, ')
          ..write('quarantinedAt: $quarantinedAt, ')
          ..write('quarantineReason: $quarantineReason')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    memberId,
    productId,
    amountCents,
    transactionType,
    notes,
    createdAt,
    synced,
    dispenserTxId,
    dispenserRequested,
    dispenserActual,
    sessionId,
    unitPriceCents,
    quarantinedAt,
    quarantineReason,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is TransactionsLocalData &&
          other.id == this.id &&
          other.memberId == this.memberId &&
          other.productId == this.productId &&
          other.amountCents == this.amountCents &&
          other.transactionType == this.transactionType &&
          other.notes == this.notes &&
          other.createdAt == this.createdAt &&
          other.synced == this.synced &&
          other.dispenserTxId == this.dispenserTxId &&
          other.dispenserRequested == this.dispenserRequested &&
          other.dispenserActual == this.dispenserActual &&
          other.sessionId == this.sessionId &&
          other.unitPriceCents == this.unitPriceCents &&
          other.quarantinedAt == this.quarantinedAt &&
          other.quarantineReason == this.quarantineReason);
}

class TransactionsLocalCompanion
    extends UpdateCompanion<TransactionsLocalData> {
  final Value<String> id;
  final Value<String> memberId;
  final Value<String?> productId;
  final Value<int> amountCents;
  final Value<String> transactionType;
  final Value<String?> notes;
  final Value<String> createdAt;
  final Value<int> synced;
  final Value<String?> dispenserTxId;
  final Value<int?> dispenserRequested;
  final Value<int?> dispenserActual;
  final Value<String?> sessionId;
  final Value<int?> unitPriceCents;
  final Value<String?> quarantinedAt;
  final Value<String?> quarantineReason;
  final Value<int> rowid;
  const TransactionsLocalCompanion({
    this.id = const Value.absent(),
    this.memberId = const Value.absent(),
    this.productId = const Value.absent(),
    this.amountCents = const Value.absent(),
    this.transactionType = const Value.absent(),
    this.notes = const Value.absent(),
    this.createdAt = const Value.absent(),
    this.synced = const Value.absent(),
    this.dispenserTxId = const Value.absent(),
    this.dispenserRequested = const Value.absent(),
    this.dispenserActual = const Value.absent(),
    this.sessionId = const Value.absent(),
    this.unitPriceCents = const Value.absent(),
    this.quarantinedAt = const Value.absent(),
    this.quarantineReason = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  TransactionsLocalCompanion.insert({
    required String id,
    required String memberId,
    this.productId = const Value.absent(),
    required int amountCents,
    required String transactionType,
    this.notes = const Value.absent(),
    required String createdAt,
    this.synced = const Value.absent(),
    this.dispenserTxId = const Value.absent(),
    this.dispenserRequested = const Value.absent(),
    this.dispenserActual = const Value.absent(),
    this.sessionId = const Value.absent(),
    this.unitPriceCents = const Value.absent(),
    this.quarantinedAt = const Value.absent(),
    this.quarantineReason = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       memberId = Value(memberId),
       amountCents = Value(amountCents),
       transactionType = Value(transactionType),
       createdAt = Value(createdAt);
  static Insertable<TransactionsLocalData> custom({
    Expression<String>? id,
    Expression<String>? memberId,
    Expression<String>? productId,
    Expression<int>? amountCents,
    Expression<String>? transactionType,
    Expression<String>? notes,
    Expression<String>? createdAt,
    Expression<int>? synced,
    Expression<String>? dispenserTxId,
    Expression<int>? dispenserRequested,
    Expression<int>? dispenserActual,
    Expression<String>? sessionId,
    Expression<int>? unitPriceCents,
    Expression<String>? quarantinedAt,
    Expression<String>? quarantineReason,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (memberId != null) 'member_id': memberId,
      if (productId != null) 'product_id': productId,
      if (amountCents != null) 'amount_cents': amountCents,
      if (transactionType != null) 'transaction_type': transactionType,
      if (notes != null) 'notes': notes,
      if (createdAt != null) 'created_at': createdAt,
      if (synced != null) 'synced': synced,
      if (dispenserTxId != null) 'dispenser_tx_id': dispenserTxId,
      if (dispenserRequested != null) 'dispenser_requested': dispenserRequested,
      if (dispenserActual != null) 'dispenser_actual': dispenserActual,
      if (sessionId != null) 'session_id': sessionId,
      if (unitPriceCents != null) 'unit_price_cents': unitPriceCents,
      if (quarantinedAt != null) 'quarantined_at': quarantinedAt,
      if (quarantineReason != null) 'quarantine_reason': quarantineReason,
      if (rowid != null) 'rowid': rowid,
    });
  }

  TransactionsLocalCompanion copyWith({
    Value<String>? id,
    Value<String>? memberId,
    Value<String?>? productId,
    Value<int>? amountCents,
    Value<String>? transactionType,
    Value<String?>? notes,
    Value<String>? createdAt,
    Value<int>? synced,
    Value<String?>? dispenserTxId,
    Value<int?>? dispenserRequested,
    Value<int?>? dispenserActual,
    Value<String?>? sessionId,
    Value<int?>? unitPriceCents,
    Value<String?>? quarantinedAt,
    Value<String?>? quarantineReason,
    Value<int>? rowid,
  }) {
    return TransactionsLocalCompanion(
      id: id ?? this.id,
      memberId: memberId ?? this.memberId,
      productId: productId ?? this.productId,
      amountCents: amountCents ?? this.amountCents,
      transactionType: transactionType ?? this.transactionType,
      notes: notes ?? this.notes,
      createdAt: createdAt ?? this.createdAt,
      synced: synced ?? this.synced,
      dispenserTxId: dispenserTxId ?? this.dispenserTxId,
      dispenserRequested: dispenserRequested ?? this.dispenserRequested,
      dispenserActual: dispenserActual ?? this.dispenserActual,
      sessionId: sessionId ?? this.sessionId,
      unitPriceCents: unitPriceCents ?? this.unitPriceCents,
      quarantinedAt: quarantinedAt ?? this.quarantinedAt,
      quarantineReason: quarantineReason ?? this.quarantineReason,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (memberId.present) {
      map['member_id'] = Variable<String>(memberId.value);
    }
    if (productId.present) {
      map['product_id'] = Variable<String>(productId.value);
    }
    if (amountCents.present) {
      map['amount_cents'] = Variable<int>(amountCents.value);
    }
    if (transactionType.present) {
      map['transaction_type'] = Variable<String>(transactionType.value);
    }
    if (notes.present) {
      map['notes'] = Variable<String>(notes.value);
    }
    if (createdAt.present) {
      map['created_at'] = Variable<String>(createdAt.value);
    }
    if (synced.present) {
      map['synced'] = Variable<int>(synced.value);
    }
    if (dispenserTxId.present) {
      map['dispenser_tx_id'] = Variable<String>(dispenserTxId.value);
    }
    if (dispenserRequested.present) {
      map['dispenser_requested'] = Variable<int>(dispenserRequested.value);
    }
    if (dispenserActual.present) {
      map['dispenser_actual'] = Variable<int>(dispenserActual.value);
    }
    if (sessionId.present) {
      map['session_id'] = Variable<String>(sessionId.value);
    }
    if (unitPriceCents.present) {
      map['unit_price_cents'] = Variable<int>(unitPriceCents.value);
    }
    if (quarantinedAt.present) {
      map['quarantined_at'] = Variable<String>(quarantinedAt.value);
    }
    if (quarantineReason.present) {
      map['quarantine_reason'] = Variable<String>(quarantineReason.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('TransactionsLocalCompanion(')
          ..write('id: $id, ')
          ..write('memberId: $memberId, ')
          ..write('productId: $productId, ')
          ..write('amountCents: $amountCents, ')
          ..write('transactionType: $transactionType, ')
          ..write('notes: $notes, ')
          ..write('createdAt: $createdAt, ')
          ..write('synced: $synced, ')
          ..write('dispenserTxId: $dispenserTxId, ')
          ..write('dispenserRequested: $dispenserRequested, ')
          ..write('dispenserActual: $dispenserActual, ')
          ..write('sessionId: $sessionId, ')
          ..write('unitPriceCents: $unitPriceCents, ')
          ..write('quarantinedAt: $quarantinedAt, ')
          ..write('quarantineReason: $quarantineReason, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $SyncStateTable extends SyncState
    with TableInfo<$SyncStateTable, SyncStateData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $SyncStateTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _keyMeta = const VerificationMeta('key');
  @override
  late final GeneratedColumn<String> key = GeneratedColumn<String>(
    'key',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _valueMeta = const VerificationMeta('value');
  @override
  late final GeneratedColumn<String> value = GeneratedColumn<String>(
    'value',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [key, value];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'sync_state';
  @override
  VerificationContext validateIntegrity(
    Insertable<SyncStateData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('key')) {
      context.handle(
        _keyMeta,
        key.isAcceptableOrUnknown(data['key']!, _keyMeta),
      );
    } else if (isInserting) {
      context.missing(_keyMeta);
    }
    if (data.containsKey('value')) {
      context.handle(
        _valueMeta,
        value.isAcceptableOrUnknown(data['value']!, _valueMeta),
      );
    } else if (isInserting) {
      context.missing(_valueMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {key};
  @override
  SyncStateData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return SyncStateData(
      key: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}key'],
      )!,
      value: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}value'],
      )!,
    );
  }

  @override
  $SyncStateTable createAlias(String alias) {
    return $SyncStateTable(attachedDatabase, alias);
  }
}

class SyncStateData extends DataClass implements Insertable<SyncStateData> {
  final String key;
  final String value;
  const SyncStateData({required this.key, required this.value});
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['key'] = Variable<String>(key);
    map['value'] = Variable<String>(value);
    return map;
  }

  SyncStateCompanion toCompanion(bool nullToAbsent) {
    return SyncStateCompanion(key: Value(key), value: Value(value));
  }

  factory SyncStateData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return SyncStateData(
      key: serializer.fromJson<String>(json['key']),
      value: serializer.fromJson<String>(json['value']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'key': serializer.toJson<String>(key),
      'value': serializer.toJson<String>(value),
    };
  }

  SyncStateData copyWith({String? key, String? value}) =>
      SyncStateData(key: key ?? this.key, value: value ?? this.value);
  SyncStateData copyWithCompanion(SyncStateCompanion data) {
    return SyncStateData(
      key: data.key.present ? data.key.value : this.key,
      value: data.value.present ? data.value.value : this.value,
    );
  }

  @override
  String toString() {
    return (StringBuffer('SyncStateData(')
          ..write('key: $key, ')
          ..write('value: $value')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(key, value);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is SyncStateData &&
          other.key == this.key &&
          other.value == this.value);
}

class SyncStateCompanion extends UpdateCompanion<SyncStateData> {
  final Value<String> key;
  final Value<String> value;
  final Value<int> rowid;
  const SyncStateCompanion({
    this.key = const Value.absent(),
    this.value = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  SyncStateCompanion.insert({
    required String key,
    required String value,
    this.rowid = const Value.absent(),
  }) : key = Value(key),
       value = Value(value);
  static Insertable<SyncStateData> custom({
    Expression<String>? key,
    Expression<String>? value,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (key != null) 'key': key,
      if (value != null) 'value': value,
      if (rowid != null) 'rowid': rowid,
    });
  }

  SyncStateCompanion copyWith({
    Value<String>? key,
    Value<String>? value,
    Value<int>? rowid,
  }) {
    return SyncStateCompanion(
      key: key ?? this.key,
      value: value ?? this.value,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (key.present) {
      map['key'] = Variable<String>(key.value);
    }
    if (value.present) {
      map['value'] = Variable<String>(value.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('SyncStateCompanion(')
          ..write('key: $key, ')
          ..write('value: $value, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $DispenserConfigTable extends DispenserConfig
    with TableInfo<$DispenserConfigTable, DispenserConfigData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $DispenserConfigTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _keyMeta = const VerificationMeta('key');
  @override
  late final GeneratedColumn<String> key = GeneratedColumn<String>(
    'key',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _valueMeta = const VerificationMeta('value');
  @override
  late final GeneratedColumn<String> value = GeneratedColumn<String>(
    'value',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [key, value];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'dispenser_config';
  @override
  VerificationContext validateIntegrity(
    Insertable<DispenserConfigData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('key')) {
      context.handle(
        _keyMeta,
        key.isAcceptableOrUnknown(data['key']!, _keyMeta),
      );
    } else if (isInserting) {
      context.missing(_keyMeta);
    }
    if (data.containsKey('value')) {
      context.handle(
        _valueMeta,
        value.isAcceptableOrUnknown(data['value']!, _valueMeta),
      );
    } else if (isInserting) {
      context.missing(_valueMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {key};
  @override
  DispenserConfigData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return DispenserConfigData(
      key: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}key'],
      )!,
      value: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}value'],
      )!,
    );
  }

  @override
  $DispenserConfigTable createAlias(String alias) {
    return $DispenserConfigTable(attachedDatabase, alias);
  }
}

class DispenserConfigData extends DataClass
    implements Insertable<DispenserConfigData> {
  final String key;
  final String value;
  const DispenserConfigData({required this.key, required this.value});
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['key'] = Variable<String>(key);
    map['value'] = Variable<String>(value);
    return map;
  }

  DispenserConfigCompanion toCompanion(bool nullToAbsent) {
    return DispenserConfigCompanion(key: Value(key), value: Value(value));
  }

  factory DispenserConfigData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return DispenserConfigData(
      key: serializer.fromJson<String>(json['key']),
      value: serializer.fromJson<String>(json['value']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'key': serializer.toJson<String>(key),
      'value': serializer.toJson<String>(value),
    };
  }

  DispenserConfigData copyWith({String? key, String? value}) =>
      DispenserConfigData(key: key ?? this.key, value: value ?? this.value);
  DispenserConfigData copyWithCompanion(DispenserConfigCompanion data) {
    return DispenserConfigData(
      key: data.key.present ? data.key.value : this.key,
      value: data.value.present ? data.value.value : this.value,
    );
  }

  @override
  String toString() {
    return (StringBuffer('DispenserConfigData(')
          ..write('key: $key, ')
          ..write('value: $value')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(key, value);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is DispenserConfigData &&
          other.key == this.key &&
          other.value == this.value);
}

class DispenserConfigCompanion extends UpdateCompanion<DispenserConfigData> {
  final Value<String> key;
  final Value<String> value;
  final Value<int> rowid;
  const DispenserConfigCompanion({
    this.key = const Value.absent(),
    this.value = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  DispenserConfigCompanion.insert({
    required String key,
    required String value,
    this.rowid = const Value.absent(),
  }) : key = Value(key),
       value = Value(value);
  static Insertable<DispenserConfigData> custom({
    Expression<String>? key,
    Expression<String>? value,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (key != null) 'key': key,
      if (value != null) 'value': value,
      if (rowid != null) 'rowid': rowid,
    });
  }

  DispenserConfigCompanion copyWith({
    Value<String>? key,
    Value<String>? value,
    Value<int>? rowid,
  }) {
    return DispenserConfigCompanion(
      key: key ?? this.key,
      value: value ?? this.value,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (key.present) {
      map['key'] = Variable<String>(key.value);
    }
    if (value.present) {
      map['value'] = Variable<String>(value.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('DispenserConfigCompanion(')
          ..write('key: $key, ')
          ..write('value: $value, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $DispenserOperationsTable extends DispenserOperations
    with TableInfo<$DispenserOperationsTable, DispenserOperation> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $DispenserOperationsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _dispenserTxIdMeta = const VerificationMeta(
    'dispenserTxId',
  );
  @override
  late final GeneratedColumn<String> dispenserTxId = GeneratedColumn<String>(
    'dispenser_tx_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _memberIdMeta = const VerificationMeta(
    'memberId',
  );
  @override
  late final GeneratedColumn<String> memberId = GeneratedColumn<String>(
    'member_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _productIdMeta = const VerificationMeta(
    'productId',
  );
  @override
  late final GeneratedColumn<String> productId = GeneratedColumn<String>(
    'product_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _priceCentsMeta = const VerificationMeta(
    'priceCents',
  );
  @override
  late final GeneratedColumn<int> priceCents = GeneratedColumn<int>(
    'price_cents',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _requestedQtyMeta = const VerificationMeta(
    'requestedQty',
  );
  @override
  late final GeneratedColumn<int> requestedQty = GeneratedColumn<int>(
    'requested_qty',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _createdAtMeta = const VerificationMeta(
    'createdAt',
  );
  @override
  late final GeneratedColumn<String> createdAt = GeneratedColumn<String>(
    'created_at',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _transactionsCreatedMeta =
      const VerificationMeta('transactionsCreated');
  @override
  late final GeneratedColumn<int> transactionsCreated = GeneratedColumn<int>(
    'transactions_created',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _lastKnownStateMeta = const VerificationMeta(
    'lastKnownState',
  );
  @override
  late final GeneratedColumn<String> lastKnownState = GeneratedColumn<String>(
    'last_known_state',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _lastKnownDispensedMeta =
      const VerificationMeta('lastKnownDispensed');
  @override
  late final GeneratedColumn<int> lastKnownDispensed = GeneratedColumn<int>(
    'last_known_dispensed',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _lastPolledAtMeta = const VerificationMeta(
    'lastPolledAt',
  );
  @override
  late final GeneratedColumn<String> lastPolledAt = GeneratedColumn<String>(
    'last_polled_at',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _pollingActiveMeta = const VerificationMeta(
    'pollingActive',
  );
  @override
  late final GeneratedColumn<int> pollingActive = GeneratedColumn<int>(
    'polling_active',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  @override
  List<GeneratedColumn> get $columns => [
    dispenserTxId,
    memberId,
    productId,
    priceCents,
    requestedQty,
    createdAt,
    transactionsCreated,
    lastKnownState,
    lastKnownDispensed,
    lastPolledAt,
    pollingActive,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'dispenser_operations';
  @override
  VerificationContext validateIntegrity(
    Insertable<DispenserOperation> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('dispenser_tx_id')) {
      context.handle(
        _dispenserTxIdMeta,
        dispenserTxId.isAcceptableOrUnknown(
          data['dispenser_tx_id']!,
          _dispenserTxIdMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_dispenserTxIdMeta);
    }
    if (data.containsKey('member_id')) {
      context.handle(
        _memberIdMeta,
        memberId.isAcceptableOrUnknown(data['member_id']!, _memberIdMeta),
      );
    } else if (isInserting) {
      context.missing(_memberIdMeta);
    }
    if (data.containsKey('product_id')) {
      context.handle(
        _productIdMeta,
        productId.isAcceptableOrUnknown(data['product_id']!, _productIdMeta),
      );
    } else if (isInserting) {
      context.missing(_productIdMeta);
    }
    if (data.containsKey('price_cents')) {
      context.handle(
        _priceCentsMeta,
        priceCents.isAcceptableOrUnknown(data['price_cents']!, _priceCentsMeta),
      );
    } else if (isInserting) {
      context.missing(_priceCentsMeta);
    }
    if (data.containsKey('requested_qty')) {
      context.handle(
        _requestedQtyMeta,
        requestedQty.isAcceptableOrUnknown(
          data['requested_qty']!,
          _requestedQtyMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_requestedQtyMeta);
    }
    if (data.containsKey('created_at')) {
      context.handle(
        _createdAtMeta,
        createdAt.isAcceptableOrUnknown(data['created_at']!, _createdAtMeta),
      );
    } else if (isInserting) {
      context.missing(_createdAtMeta);
    }
    if (data.containsKey('transactions_created')) {
      context.handle(
        _transactionsCreatedMeta,
        transactionsCreated.isAcceptableOrUnknown(
          data['transactions_created']!,
          _transactionsCreatedMeta,
        ),
      );
    }
    if (data.containsKey('last_known_state')) {
      context.handle(
        _lastKnownStateMeta,
        lastKnownState.isAcceptableOrUnknown(
          data['last_known_state']!,
          _lastKnownStateMeta,
        ),
      );
    }
    if (data.containsKey('last_known_dispensed')) {
      context.handle(
        _lastKnownDispensedMeta,
        lastKnownDispensed.isAcceptableOrUnknown(
          data['last_known_dispensed']!,
          _lastKnownDispensedMeta,
        ),
      );
    }
    if (data.containsKey('last_polled_at')) {
      context.handle(
        _lastPolledAtMeta,
        lastPolledAt.isAcceptableOrUnknown(
          data['last_polled_at']!,
          _lastPolledAtMeta,
        ),
      );
    }
    if (data.containsKey('polling_active')) {
      context.handle(
        _pollingActiveMeta,
        pollingActive.isAcceptableOrUnknown(
          data['polling_active']!,
          _pollingActiveMeta,
        ),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {dispenserTxId};
  @override
  DispenserOperation map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return DispenserOperation(
      dispenserTxId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}dispenser_tx_id'],
      )!,
      memberId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}member_id'],
      )!,
      productId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}product_id'],
      )!,
      priceCents: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}price_cents'],
      )!,
      requestedQty: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}requested_qty'],
      )!,
      createdAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}created_at'],
      )!,
      transactionsCreated: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}transactions_created'],
      )!,
      lastKnownState: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}last_known_state'],
      ),
      lastKnownDispensed: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}last_known_dispensed'],
      )!,
      lastPolledAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}last_polled_at'],
      ),
      pollingActive: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}polling_active'],
      )!,
    );
  }

  @override
  $DispenserOperationsTable createAlias(String alias) {
    return $DispenserOperationsTable(attachedDatabase, alias);
  }
}

class DispenserOperation extends DataClass
    implements Insertable<DispenserOperation> {
  /// ESP8266 transaction ID (primary key)
  final String dispenserTxId;

  /// Member ID who initiated the purchase
  final String memberId;

  /// Product ID being purchased (token product)
  final String productId;

  /// Price per token in cents
  final int priceCents;

  /// Number of tokens requested from dispenser
  final int requestedQty;

  /// When this operation was started
  final String createdAt;

  /// How many transactions we've already created for this operation
  /// Used by recovery service to detect missing transactions
  final int transactionsCreated;

  /// Last known state from ESP8266 ("dispensing", "done", "error")
  /// Determines when operation can be cleaned up
  final String? lastKnownState;

  /// Last known dispensed count from ESP8266
  /// Compared against transactionsCreated to detect discrepancies
  final int lastKnownDispensed;

  /// Last time we polled ESP8266 (ISO timestamp)
  /// Recovery service skips operations polled within last 30 seconds
  final String? lastPolledAt;

  /// Whether polling is currently active (1=true, 0=false)
  /// Recovery service skips operations where polling_active = 1
  final int pollingActive;
  const DispenserOperation({
    required this.dispenserTxId,
    required this.memberId,
    required this.productId,
    required this.priceCents,
    required this.requestedQty,
    required this.createdAt,
    required this.transactionsCreated,
    this.lastKnownState,
    required this.lastKnownDispensed,
    this.lastPolledAt,
    required this.pollingActive,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['dispenser_tx_id'] = Variable<String>(dispenserTxId);
    map['member_id'] = Variable<String>(memberId);
    map['product_id'] = Variable<String>(productId);
    map['price_cents'] = Variable<int>(priceCents);
    map['requested_qty'] = Variable<int>(requestedQty);
    map['created_at'] = Variable<String>(createdAt);
    map['transactions_created'] = Variable<int>(transactionsCreated);
    if (!nullToAbsent || lastKnownState != null) {
      map['last_known_state'] = Variable<String>(lastKnownState);
    }
    map['last_known_dispensed'] = Variable<int>(lastKnownDispensed);
    if (!nullToAbsent || lastPolledAt != null) {
      map['last_polled_at'] = Variable<String>(lastPolledAt);
    }
    map['polling_active'] = Variable<int>(pollingActive);
    return map;
  }

  DispenserOperationsCompanion toCompanion(bool nullToAbsent) {
    return DispenserOperationsCompanion(
      dispenserTxId: Value(dispenserTxId),
      memberId: Value(memberId),
      productId: Value(productId),
      priceCents: Value(priceCents),
      requestedQty: Value(requestedQty),
      createdAt: Value(createdAt),
      transactionsCreated: Value(transactionsCreated),
      lastKnownState: lastKnownState == null && nullToAbsent
          ? const Value.absent()
          : Value(lastKnownState),
      lastKnownDispensed: Value(lastKnownDispensed),
      lastPolledAt: lastPolledAt == null && nullToAbsent
          ? const Value.absent()
          : Value(lastPolledAt),
      pollingActive: Value(pollingActive),
    );
  }

  factory DispenserOperation.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return DispenserOperation(
      dispenserTxId: serializer.fromJson<String>(json['dispenserTxId']),
      memberId: serializer.fromJson<String>(json['memberId']),
      productId: serializer.fromJson<String>(json['productId']),
      priceCents: serializer.fromJson<int>(json['priceCents']),
      requestedQty: serializer.fromJson<int>(json['requestedQty']),
      createdAt: serializer.fromJson<String>(json['createdAt']),
      transactionsCreated: serializer.fromJson<int>(
        json['transactionsCreated'],
      ),
      lastKnownState: serializer.fromJson<String?>(json['lastKnownState']),
      lastKnownDispensed: serializer.fromJson<int>(json['lastKnownDispensed']),
      lastPolledAt: serializer.fromJson<String?>(json['lastPolledAt']),
      pollingActive: serializer.fromJson<int>(json['pollingActive']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'dispenserTxId': serializer.toJson<String>(dispenserTxId),
      'memberId': serializer.toJson<String>(memberId),
      'productId': serializer.toJson<String>(productId),
      'priceCents': serializer.toJson<int>(priceCents),
      'requestedQty': serializer.toJson<int>(requestedQty),
      'createdAt': serializer.toJson<String>(createdAt),
      'transactionsCreated': serializer.toJson<int>(transactionsCreated),
      'lastKnownState': serializer.toJson<String?>(lastKnownState),
      'lastKnownDispensed': serializer.toJson<int>(lastKnownDispensed),
      'lastPolledAt': serializer.toJson<String?>(lastPolledAt),
      'pollingActive': serializer.toJson<int>(pollingActive),
    };
  }

  DispenserOperation copyWith({
    String? dispenserTxId,
    String? memberId,
    String? productId,
    int? priceCents,
    int? requestedQty,
    String? createdAt,
    int? transactionsCreated,
    Value<String?> lastKnownState = const Value.absent(),
    int? lastKnownDispensed,
    Value<String?> lastPolledAt = const Value.absent(),
    int? pollingActive,
  }) => DispenserOperation(
    dispenserTxId: dispenserTxId ?? this.dispenserTxId,
    memberId: memberId ?? this.memberId,
    productId: productId ?? this.productId,
    priceCents: priceCents ?? this.priceCents,
    requestedQty: requestedQty ?? this.requestedQty,
    createdAt: createdAt ?? this.createdAt,
    transactionsCreated: transactionsCreated ?? this.transactionsCreated,
    lastKnownState: lastKnownState.present
        ? lastKnownState.value
        : this.lastKnownState,
    lastKnownDispensed: lastKnownDispensed ?? this.lastKnownDispensed,
    lastPolledAt: lastPolledAt.present ? lastPolledAt.value : this.lastPolledAt,
    pollingActive: pollingActive ?? this.pollingActive,
  );
  DispenserOperation copyWithCompanion(DispenserOperationsCompanion data) {
    return DispenserOperation(
      dispenserTxId: data.dispenserTxId.present
          ? data.dispenserTxId.value
          : this.dispenserTxId,
      memberId: data.memberId.present ? data.memberId.value : this.memberId,
      productId: data.productId.present ? data.productId.value : this.productId,
      priceCents: data.priceCents.present
          ? data.priceCents.value
          : this.priceCents,
      requestedQty: data.requestedQty.present
          ? data.requestedQty.value
          : this.requestedQty,
      createdAt: data.createdAt.present ? data.createdAt.value : this.createdAt,
      transactionsCreated: data.transactionsCreated.present
          ? data.transactionsCreated.value
          : this.transactionsCreated,
      lastKnownState: data.lastKnownState.present
          ? data.lastKnownState.value
          : this.lastKnownState,
      lastKnownDispensed: data.lastKnownDispensed.present
          ? data.lastKnownDispensed.value
          : this.lastKnownDispensed,
      lastPolledAt: data.lastPolledAt.present
          ? data.lastPolledAt.value
          : this.lastPolledAt,
      pollingActive: data.pollingActive.present
          ? data.pollingActive.value
          : this.pollingActive,
    );
  }

  @override
  String toString() {
    return (StringBuffer('DispenserOperation(')
          ..write('dispenserTxId: $dispenserTxId, ')
          ..write('memberId: $memberId, ')
          ..write('productId: $productId, ')
          ..write('priceCents: $priceCents, ')
          ..write('requestedQty: $requestedQty, ')
          ..write('createdAt: $createdAt, ')
          ..write('transactionsCreated: $transactionsCreated, ')
          ..write('lastKnownState: $lastKnownState, ')
          ..write('lastKnownDispensed: $lastKnownDispensed, ')
          ..write('lastPolledAt: $lastPolledAt, ')
          ..write('pollingActive: $pollingActive')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    dispenserTxId,
    memberId,
    productId,
    priceCents,
    requestedQty,
    createdAt,
    transactionsCreated,
    lastKnownState,
    lastKnownDispensed,
    lastPolledAt,
    pollingActive,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is DispenserOperation &&
          other.dispenserTxId == this.dispenserTxId &&
          other.memberId == this.memberId &&
          other.productId == this.productId &&
          other.priceCents == this.priceCents &&
          other.requestedQty == this.requestedQty &&
          other.createdAt == this.createdAt &&
          other.transactionsCreated == this.transactionsCreated &&
          other.lastKnownState == this.lastKnownState &&
          other.lastKnownDispensed == this.lastKnownDispensed &&
          other.lastPolledAt == this.lastPolledAt &&
          other.pollingActive == this.pollingActive);
}

class DispenserOperationsCompanion extends UpdateCompanion<DispenserOperation> {
  final Value<String> dispenserTxId;
  final Value<String> memberId;
  final Value<String> productId;
  final Value<int> priceCents;
  final Value<int> requestedQty;
  final Value<String> createdAt;
  final Value<int> transactionsCreated;
  final Value<String?> lastKnownState;
  final Value<int> lastKnownDispensed;
  final Value<String?> lastPolledAt;
  final Value<int> pollingActive;
  final Value<int> rowid;
  const DispenserOperationsCompanion({
    this.dispenserTxId = const Value.absent(),
    this.memberId = const Value.absent(),
    this.productId = const Value.absent(),
    this.priceCents = const Value.absent(),
    this.requestedQty = const Value.absent(),
    this.createdAt = const Value.absent(),
    this.transactionsCreated = const Value.absent(),
    this.lastKnownState = const Value.absent(),
    this.lastKnownDispensed = const Value.absent(),
    this.lastPolledAt = const Value.absent(),
    this.pollingActive = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  DispenserOperationsCompanion.insert({
    required String dispenserTxId,
    required String memberId,
    required String productId,
    required int priceCents,
    required int requestedQty,
    required String createdAt,
    this.transactionsCreated = const Value.absent(),
    this.lastKnownState = const Value.absent(),
    this.lastKnownDispensed = const Value.absent(),
    this.lastPolledAt = const Value.absent(),
    this.pollingActive = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : dispenserTxId = Value(dispenserTxId),
       memberId = Value(memberId),
       productId = Value(productId),
       priceCents = Value(priceCents),
       requestedQty = Value(requestedQty),
       createdAt = Value(createdAt);
  static Insertable<DispenserOperation> custom({
    Expression<String>? dispenserTxId,
    Expression<String>? memberId,
    Expression<String>? productId,
    Expression<int>? priceCents,
    Expression<int>? requestedQty,
    Expression<String>? createdAt,
    Expression<int>? transactionsCreated,
    Expression<String>? lastKnownState,
    Expression<int>? lastKnownDispensed,
    Expression<String>? lastPolledAt,
    Expression<int>? pollingActive,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (dispenserTxId != null) 'dispenser_tx_id': dispenserTxId,
      if (memberId != null) 'member_id': memberId,
      if (productId != null) 'product_id': productId,
      if (priceCents != null) 'price_cents': priceCents,
      if (requestedQty != null) 'requested_qty': requestedQty,
      if (createdAt != null) 'created_at': createdAt,
      if (transactionsCreated != null)
        'transactions_created': transactionsCreated,
      if (lastKnownState != null) 'last_known_state': lastKnownState,
      if (lastKnownDispensed != null)
        'last_known_dispensed': lastKnownDispensed,
      if (lastPolledAt != null) 'last_polled_at': lastPolledAt,
      if (pollingActive != null) 'polling_active': pollingActive,
      if (rowid != null) 'rowid': rowid,
    });
  }

  DispenserOperationsCompanion copyWith({
    Value<String>? dispenserTxId,
    Value<String>? memberId,
    Value<String>? productId,
    Value<int>? priceCents,
    Value<int>? requestedQty,
    Value<String>? createdAt,
    Value<int>? transactionsCreated,
    Value<String?>? lastKnownState,
    Value<int>? lastKnownDispensed,
    Value<String?>? lastPolledAt,
    Value<int>? pollingActive,
    Value<int>? rowid,
  }) {
    return DispenserOperationsCompanion(
      dispenserTxId: dispenserTxId ?? this.dispenserTxId,
      memberId: memberId ?? this.memberId,
      productId: productId ?? this.productId,
      priceCents: priceCents ?? this.priceCents,
      requestedQty: requestedQty ?? this.requestedQty,
      createdAt: createdAt ?? this.createdAt,
      transactionsCreated: transactionsCreated ?? this.transactionsCreated,
      lastKnownState: lastKnownState ?? this.lastKnownState,
      lastKnownDispensed: lastKnownDispensed ?? this.lastKnownDispensed,
      lastPolledAt: lastPolledAt ?? this.lastPolledAt,
      pollingActive: pollingActive ?? this.pollingActive,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (dispenserTxId.present) {
      map['dispenser_tx_id'] = Variable<String>(dispenserTxId.value);
    }
    if (memberId.present) {
      map['member_id'] = Variable<String>(memberId.value);
    }
    if (productId.present) {
      map['product_id'] = Variable<String>(productId.value);
    }
    if (priceCents.present) {
      map['price_cents'] = Variable<int>(priceCents.value);
    }
    if (requestedQty.present) {
      map['requested_qty'] = Variable<int>(requestedQty.value);
    }
    if (createdAt.present) {
      map['created_at'] = Variable<String>(createdAt.value);
    }
    if (transactionsCreated.present) {
      map['transactions_created'] = Variable<int>(transactionsCreated.value);
    }
    if (lastKnownState.present) {
      map['last_known_state'] = Variable<String>(lastKnownState.value);
    }
    if (lastKnownDispensed.present) {
      map['last_known_dispensed'] = Variable<int>(lastKnownDispensed.value);
    }
    if (lastPolledAt.present) {
      map['last_polled_at'] = Variable<String>(lastPolledAt.value);
    }
    if (pollingActive.present) {
      map['polling_active'] = Variable<int>(pollingActive.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('DispenserOperationsCompanion(')
          ..write('dispenserTxId: $dispenserTxId, ')
          ..write('memberId: $memberId, ')
          ..write('productId: $productId, ')
          ..write('priceCents: $priceCents, ')
          ..write('requestedQty: $requestedQty, ')
          ..write('createdAt: $createdAt, ')
          ..write('transactionsCreated: $transactionsCreated, ')
          ..write('lastKnownState: $lastKnownState, ')
          ..write('lastKnownDispensed: $lastKnownDispensed, ')
          ..write('lastPolledAt: $lastPolledAt, ')
          ..write('pollingActive: $pollingActive, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

abstract class _$ClubBarDatabase extends GeneratedDatabase {
  _$ClubBarDatabase(QueryExecutor e) : super(e);
  $ClubBarDatabaseManager get managers => $ClubBarDatabaseManager(this);
  late final $MembersCacheTable membersCache = $MembersCacheTable(this);
  late final $CategoriesCacheTable categoriesCache = $CategoriesCacheTable(
    this,
  );
  late final $ProductsCacheTable productsCache = $ProductsCacheTable(this);
  late final $TransactionsLocalTable transactionsLocal =
      $TransactionsLocalTable(this);
  late final $SyncStateTable syncState = $SyncStateTable(this);
  late final $DispenserConfigTable dispenserConfig = $DispenserConfigTable(
    this,
  );
  late final $DispenserOperationsTable dispenserOperations =
      $DispenserOperationsTable(this);
  @override
  Iterable<TableInfo<Table, Object?>> get allTables =>
      allSchemaEntities.whereType<TableInfo<Table, Object?>>();
  @override
  List<DatabaseSchemaEntity> get allSchemaEntities => [
    membersCache,
    categoriesCache,
    productsCache,
    transactionsLocal,
    syncState,
    dispenserConfig,
    dispenserOperations,
  ];
}

typedef $$MembersCacheTableCreateCompanionBuilder =
    MembersCacheCompanion Function({
      required String id,
      Value<String?> cardUid,
      Value<String?> firstName,
      Value<String?> lastName,
      Value<String?> dateOfBirth,
      required String preferredLanguage,
      Value<int> isActive,
      required int isSepaValid,
      Value<int> balanceCents,
      required String updatedAt,
      Value<String?> deletedAt,
      Value<int> rowid,
    });
typedef $$MembersCacheTableUpdateCompanionBuilder =
    MembersCacheCompanion Function({
      Value<String> id,
      Value<String?> cardUid,
      Value<String?> firstName,
      Value<String?> lastName,
      Value<String?> dateOfBirth,
      Value<String> preferredLanguage,
      Value<int> isActive,
      Value<int> isSepaValid,
      Value<int> balanceCents,
      Value<String> updatedAt,
      Value<String?> deletedAt,
      Value<int> rowid,
    });

final class $$MembersCacheTableReferences
    extends
        BaseReferences<
          _$ClubBarDatabase,
          $MembersCacheTable,
          MembersCacheData
        > {
  $$MembersCacheTableReferences(super.$_db, super.$_table, super.$_typedResult);

  static MultiTypedResultKey<
    $TransactionsLocalTable,
    List<TransactionsLocalData>
  >
  _transactionsLocalRefsTable(_$ClubBarDatabase db) =>
      MultiTypedResultKey.fromTable(
        db.transactionsLocal,
        aliasName: 'members_cache__id__transactions_local__member_id',
      );

  $$TransactionsLocalTableProcessedTableManager get transactionsLocalRefs {
    final manager = $$TransactionsLocalTableTableManager(
      $_db,
      $_db.transactionsLocal,
    ).filter((f) => f.memberId.id.sqlEquals($_itemColumn<String>('id')!));

    final cache = $_typedResult.readTableOrNull(
      _transactionsLocalRefsTable($_db),
    );
    return ProcessedTableManager(
      manager.$state.copyWith(prefetchedData: cache),
    );
  }
}

class $$MembersCacheTableFilterComposer
    extends Composer<_$ClubBarDatabase, $MembersCacheTable> {
  $$MembersCacheTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get cardUid => $composableBuilder(
    column: $table.cardUid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get firstName => $composableBuilder(
    column: $table.firstName,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get lastName => $composableBuilder(
    column: $table.lastName,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get dateOfBirth => $composableBuilder(
    column: $table.dateOfBirth,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get preferredLanguage => $composableBuilder(
    column: $table.preferredLanguage,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get isActive => $composableBuilder(
    column: $table.isActive,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get isSepaValid => $composableBuilder(
    column: $table.isSepaValid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get balanceCents => $composableBuilder(
    column: $table.balanceCents,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get deletedAt => $composableBuilder(
    column: $table.deletedAt,
    builder: (column) => ColumnFilters(column),
  );

  Expression<bool> transactionsLocalRefs(
    Expression<bool> Function($$TransactionsLocalTableFilterComposer f) f,
  ) {
    final $$TransactionsLocalTableFilterComposer composer = $composerBuilder(
      composer: this,
      getCurrentColumn: (t) => t.id,
      referencedTable: $db.transactionsLocal,
      getReferencedColumn: (t) => t.memberId,
      builder:
          (
            joinBuilder, {
            $addJoinBuilderToRootComposer,
            $removeJoinBuilderFromRootComposer,
          }) => $$TransactionsLocalTableFilterComposer(
            $db: $db,
            $table: $db.transactionsLocal,
            $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
            joinBuilder: joinBuilder,
            $removeJoinBuilderFromRootComposer:
                $removeJoinBuilderFromRootComposer,
          ),
    );
    return f(composer);
  }
}

class $$MembersCacheTableOrderingComposer
    extends Composer<_$ClubBarDatabase, $MembersCacheTable> {
  $$MembersCacheTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get cardUid => $composableBuilder(
    column: $table.cardUid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get firstName => $composableBuilder(
    column: $table.firstName,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get lastName => $composableBuilder(
    column: $table.lastName,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get dateOfBirth => $composableBuilder(
    column: $table.dateOfBirth,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get preferredLanguage => $composableBuilder(
    column: $table.preferredLanguage,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get isActive => $composableBuilder(
    column: $table.isActive,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get isSepaValid => $composableBuilder(
    column: $table.isSepaValid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get balanceCents => $composableBuilder(
    column: $table.balanceCents,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get deletedAt => $composableBuilder(
    column: $table.deletedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$MembersCacheTableAnnotationComposer
    extends Composer<_$ClubBarDatabase, $MembersCacheTable> {
  $$MembersCacheTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get cardUid =>
      $composableBuilder(column: $table.cardUid, builder: (column) => column);

  GeneratedColumn<String> get firstName =>
      $composableBuilder(column: $table.firstName, builder: (column) => column);

  GeneratedColumn<String> get lastName =>
      $composableBuilder(column: $table.lastName, builder: (column) => column);

  GeneratedColumn<String> get dateOfBirth => $composableBuilder(
    column: $table.dateOfBirth,
    builder: (column) => column,
  );

  GeneratedColumn<String> get preferredLanguage => $composableBuilder(
    column: $table.preferredLanguage,
    builder: (column) => column,
  );

  GeneratedColumn<int> get isActive =>
      $composableBuilder(column: $table.isActive, builder: (column) => column);

  GeneratedColumn<int> get isSepaValid => $composableBuilder(
    column: $table.isSepaValid,
    builder: (column) => column,
  );

  GeneratedColumn<int> get balanceCents => $composableBuilder(
    column: $table.balanceCents,
    builder: (column) => column,
  );

  GeneratedColumn<String> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);

  GeneratedColumn<String> get deletedAt =>
      $composableBuilder(column: $table.deletedAt, builder: (column) => column);

  Expression<T> transactionsLocalRefs<T extends Object>(
    Expression<T> Function($$TransactionsLocalTableAnnotationComposer a) f,
  ) {
    final $$TransactionsLocalTableAnnotationComposer composer =
        $composerBuilder(
          composer: this,
          getCurrentColumn: (t) => t.id,
          referencedTable: $db.transactionsLocal,
          getReferencedColumn: (t) => t.memberId,
          builder:
              (
                joinBuilder, {
                $addJoinBuilderToRootComposer,
                $removeJoinBuilderFromRootComposer,
              }) => $$TransactionsLocalTableAnnotationComposer(
                $db: $db,
                $table: $db.transactionsLocal,
                $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
                joinBuilder: joinBuilder,
                $removeJoinBuilderFromRootComposer:
                    $removeJoinBuilderFromRootComposer,
              ),
        );
    return f(composer);
  }
}

class $$MembersCacheTableTableManager
    extends
        RootTableManager<
          _$ClubBarDatabase,
          $MembersCacheTable,
          MembersCacheData,
          $$MembersCacheTableFilterComposer,
          $$MembersCacheTableOrderingComposer,
          $$MembersCacheTableAnnotationComposer,
          $$MembersCacheTableCreateCompanionBuilder,
          $$MembersCacheTableUpdateCompanionBuilder,
          (MembersCacheData, $$MembersCacheTableReferences),
          MembersCacheData,
          PrefetchHooks Function({bool transactionsLocalRefs})
        > {
  $$MembersCacheTableTableManager(
    _$ClubBarDatabase db,
    $MembersCacheTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$MembersCacheTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$MembersCacheTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$MembersCacheTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String?> cardUid = const Value.absent(),
                Value<String?> firstName = const Value.absent(),
                Value<String?> lastName = const Value.absent(),
                Value<String?> dateOfBirth = const Value.absent(),
                Value<String> preferredLanguage = const Value.absent(),
                Value<int> isActive = const Value.absent(),
                Value<int> isSepaValid = const Value.absent(),
                Value<int> balanceCents = const Value.absent(),
                Value<String> updatedAt = const Value.absent(),
                Value<String?> deletedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => MembersCacheCompanion(
                id: id,
                cardUid: cardUid,
                firstName: firstName,
                lastName: lastName,
                dateOfBirth: dateOfBirth,
                preferredLanguage: preferredLanguage,
                isActive: isActive,
                isSepaValid: isSepaValid,
                balanceCents: balanceCents,
                updatedAt: updatedAt,
                deletedAt: deletedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                Value<String?> cardUid = const Value.absent(),
                Value<String?> firstName = const Value.absent(),
                Value<String?> lastName = const Value.absent(),
                Value<String?> dateOfBirth = const Value.absent(),
                required String preferredLanguage,
                Value<int> isActive = const Value.absent(),
                required int isSepaValid,
                Value<int> balanceCents = const Value.absent(),
                required String updatedAt,
                Value<String?> deletedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => MembersCacheCompanion.insert(
                id: id,
                cardUid: cardUid,
                firstName: firstName,
                lastName: lastName,
                dateOfBirth: dateOfBirth,
                preferredLanguage: preferredLanguage,
                isActive: isActive,
                isSepaValid: isSepaValid,
                balanceCents: balanceCents,
                updatedAt: updatedAt,
                deletedAt: deletedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable(table),
                  $$MembersCacheTableReferences(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: ({transactionsLocalRefs = false}) {
            return PrefetchHooks(
              db: db,
              explicitlyWatchedTables: [
                if (transactionsLocalRefs) db.transactionsLocal,
              ],
              addJoins: null,
              getPrefetchedDataCallback: (items) async {
                return [
                  if (transactionsLocalRefs)
                    await $_getPrefetchedData<
                      MembersCacheData,
                      $MembersCacheTable,
                      TransactionsLocalData
                    >(
                      currentTable: table,
                      referencedTable: $$MembersCacheTableReferences
                          ._transactionsLocalRefsTable(db),
                      managerFromTypedResult: (p0) =>
                          $$MembersCacheTableReferences(
                            db,
                            table,
                            p0,
                          ).transactionsLocalRefs,
                      referencedItemsForCurrentItem: (item, referencedItems) =>
                          referencedItems.where((e) => e.memberId == item.id),
                      typedResults: items,
                    ),
                ];
              },
            );
          },
        ),
      );
}

typedef $$MembersCacheTableProcessedTableManager =
    ProcessedTableManager<
      _$ClubBarDatabase,
      $MembersCacheTable,
      MembersCacheData,
      $$MembersCacheTableFilterComposer,
      $$MembersCacheTableOrderingComposer,
      $$MembersCacheTableAnnotationComposer,
      $$MembersCacheTableCreateCompanionBuilder,
      $$MembersCacheTableUpdateCompanionBuilder,
      (MembersCacheData, $$MembersCacheTableReferences),
      MembersCacheData,
      PrefetchHooks Function({bool transactionsLocalRefs})
    >;
typedef $$CategoriesCacheTableCreateCompanionBuilder =
    CategoriesCacheCompanion Function({
      required String id,
      required String names,
      Value<int> isActive,
      Value<String?> iconName,
      required String updatedAt,
      Value<String?> deletedAt,
      Value<int> rowid,
    });
typedef $$CategoriesCacheTableUpdateCompanionBuilder =
    CategoriesCacheCompanion Function({
      Value<String> id,
      Value<String> names,
      Value<int> isActive,
      Value<String?> iconName,
      Value<String> updatedAt,
      Value<String?> deletedAt,
      Value<int> rowid,
    });

final class $$CategoriesCacheTableReferences
    extends
        BaseReferences<
          _$ClubBarDatabase,
          $CategoriesCacheTable,
          CategoriesCacheData
        > {
  $$CategoriesCacheTableReferences(
    super.$_db,
    super.$_table,
    super.$_typedResult,
  );

  static MultiTypedResultKey<$ProductsCacheTable, List<ProductsCacheData>>
  _productsCacheRefsTable(_$ClubBarDatabase db) =>
      MultiTypedResultKey.fromTable(
        db.productsCache,
        aliasName: 'categories_cache__id__products_cache__category_id',
      );

  $$ProductsCacheTableProcessedTableManager get productsCacheRefs {
    final manager = $$ProductsCacheTableTableManager(
      $_db,
      $_db.productsCache,
    ).filter((f) => f.categoryId.id.sqlEquals($_itemColumn<String>('id')!));

    final cache = $_typedResult.readTableOrNull(_productsCacheRefsTable($_db));
    return ProcessedTableManager(
      manager.$state.copyWith(prefetchedData: cache),
    );
  }
}

class $$CategoriesCacheTableFilterComposer
    extends Composer<_$ClubBarDatabase, $CategoriesCacheTable> {
  $$CategoriesCacheTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get names => $composableBuilder(
    column: $table.names,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get isActive => $composableBuilder(
    column: $table.isActive,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get iconName => $composableBuilder(
    column: $table.iconName,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get deletedAt => $composableBuilder(
    column: $table.deletedAt,
    builder: (column) => ColumnFilters(column),
  );

  Expression<bool> productsCacheRefs(
    Expression<bool> Function($$ProductsCacheTableFilterComposer f) f,
  ) {
    final $$ProductsCacheTableFilterComposer composer = $composerBuilder(
      composer: this,
      getCurrentColumn: (t) => t.id,
      referencedTable: $db.productsCache,
      getReferencedColumn: (t) => t.categoryId,
      builder:
          (
            joinBuilder, {
            $addJoinBuilderToRootComposer,
            $removeJoinBuilderFromRootComposer,
          }) => $$ProductsCacheTableFilterComposer(
            $db: $db,
            $table: $db.productsCache,
            $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
            joinBuilder: joinBuilder,
            $removeJoinBuilderFromRootComposer:
                $removeJoinBuilderFromRootComposer,
          ),
    );
    return f(composer);
  }
}

class $$CategoriesCacheTableOrderingComposer
    extends Composer<_$ClubBarDatabase, $CategoriesCacheTable> {
  $$CategoriesCacheTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get names => $composableBuilder(
    column: $table.names,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get isActive => $composableBuilder(
    column: $table.isActive,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get iconName => $composableBuilder(
    column: $table.iconName,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get deletedAt => $composableBuilder(
    column: $table.deletedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$CategoriesCacheTableAnnotationComposer
    extends Composer<_$ClubBarDatabase, $CategoriesCacheTable> {
  $$CategoriesCacheTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get names =>
      $composableBuilder(column: $table.names, builder: (column) => column);

  GeneratedColumn<int> get isActive =>
      $composableBuilder(column: $table.isActive, builder: (column) => column);

  GeneratedColumn<String> get iconName =>
      $composableBuilder(column: $table.iconName, builder: (column) => column);

  GeneratedColumn<String> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);

  GeneratedColumn<String> get deletedAt =>
      $composableBuilder(column: $table.deletedAt, builder: (column) => column);

  Expression<T> productsCacheRefs<T extends Object>(
    Expression<T> Function($$ProductsCacheTableAnnotationComposer a) f,
  ) {
    final $$ProductsCacheTableAnnotationComposer composer = $composerBuilder(
      composer: this,
      getCurrentColumn: (t) => t.id,
      referencedTable: $db.productsCache,
      getReferencedColumn: (t) => t.categoryId,
      builder:
          (
            joinBuilder, {
            $addJoinBuilderToRootComposer,
            $removeJoinBuilderFromRootComposer,
          }) => $$ProductsCacheTableAnnotationComposer(
            $db: $db,
            $table: $db.productsCache,
            $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
            joinBuilder: joinBuilder,
            $removeJoinBuilderFromRootComposer:
                $removeJoinBuilderFromRootComposer,
          ),
    );
    return f(composer);
  }
}

class $$CategoriesCacheTableTableManager
    extends
        RootTableManager<
          _$ClubBarDatabase,
          $CategoriesCacheTable,
          CategoriesCacheData,
          $$CategoriesCacheTableFilterComposer,
          $$CategoriesCacheTableOrderingComposer,
          $$CategoriesCacheTableAnnotationComposer,
          $$CategoriesCacheTableCreateCompanionBuilder,
          $$CategoriesCacheTableUpdateCompanionBuilder,
          (CategoriesCacheData, $$CategoriesCacheTableReferences),
          CategoriesCacheData,
          PrefetchHooks Function({bool productsCacheRefs})
        > {
  $$CategoriesCacheTableTableManager(
    _$ClubBarDatabase db,
    $CategoriesCacheTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$CategoriesCacheTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$CategoriesCacheTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$CategoriesCacheTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> names = const Value.absent(),
                Value<int> isActive = const Value.absent(),
                Value<String?> iconName = const Value.absent(),
                Value<String> updatedAt = const Value.absent(),
                Value<String?> deletedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => CategoriesCacheCompanion(
                id: id,
                names: names,
                isActive: isActive,
                iconName: iconName,
                updatedAt: updatedAt,
                deletedAt: deletedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String names,
                Value<int> isActive = const Value.absent(),
                Value<String?> iconName = const Value.absent(),
                required String updatedAt,
                Value<String?> deletedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => CategoriesCacheCompanion.insert(
                id: id,
                names: names,
                isActive: isActive,
                iconName: iconName,
                updatedAt: updatedAt,
                deletedAt: deletedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable(table),
                  $$CategoriesCacheTableReferences(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: ({productsCacheRefs = false}) {
            return PrefetchHooks(
              db: db,
              explicitlyWatchedTables: [
                if (productsCacheRefs) db.productsCache,
              ],
              addJoins: null,
              getPrefetchedDataCallback: (items) async {
                return [
                  if (productsCacheRefs)
                    await $_getPrefetchedData<
                      CategoriesCacheData,
                      $CategoriesCacheTable,
                      ProductsCacheData
                    >(
                      currentTable: table,
                      referencedTable: $$CategoriesCacheTableReferences
                          ._productsCacheRefsTable(db),
                      managerFromTypedResult: (p0) =>
                          $$CategoriesCacheTableReferences(
                            db,
                            table,
                            p0,
                          ).productsCacheRefs,
                      referencedItemsForCurrentItem: (item, referencedItems) =>
                          referencedItems.where((e) => e.categoryId == item.id),
                      typedResults: items,
                    ),
                ];
              },
            );
          },
        ),
      );
}

typedef $$CategoriesCacheTableProcessedTableManager =
    ProcessedTableManager<
      _$ClubBarDatabase,
      $CategoriesCacheTable,
      CategoriesCacheData,
      $$CategoriesCacheTableFilterComposer,
      $$CategoriesCacheTableOrderingComposer,
      $$CategoriesCacheTableAnnotationComposer,
      $$CategoriesCacheTableCreateCompanionBuilder,
      $$CategoriesCacheTableUpdateCompanionBuilder,
      (CategoriesCacheData, $$CategoriesCacheTableReferences),
      CategoriesCacheData,
      PrefetchHooks Function({bool productsCacheRefs})
    >;
typedef $$ProductsCacheTableCreateCompanionBuilder =
    ProductsCacheCompanion Function({
      required String id,
      required String categoryId,
      required String names,
      Value<String?> descriptions,
      required int priceCents,
      Value<int> isActive,
      Value<int> requiresDispenser,
      Value<int?> minAge,
      Value<String?> iconName,
      required String updatedAt,
      Value<String?> deletedAt,
      Value<int> rowid,
    });
typedef $$ProductsCacheTableUpdateCompanionBuilder =
    ProductsCacheCompanion Function({
      Value<String> id,
      Value<String> categoryId,
      Value<String> names,
      Value<String?> descriptions,
      Value<int> priceCents,
      Value<int> isActive,
      Value<int> requiresDispenser,
      Value<int?> minAge,
      Value<String?> iconName,
      Value<String> updatedAt,
      Value<String?> deletedAt,
      Value<int> rowid,
    });

final class $$ProductsCacheTableReferences
    extends
        BaseReferences<
          _$ClubBarDatabase,
          $ProductsCacheTable,
          ProductsCacheData
        > {
  $$ProductsCacheTableReferences(
    super.$_db,
    super.$_table,
    super.$_typedResult,
  );

  static $CategoriesCacheTable _categoryIdTable(_$ClubBarDatabase db) => db
      .categoriesCache
      .createAlias('products_cache__category_id__categories_cache__id');

  $$CategoriesCacheTableProcessedTableManager get categoryId {
    final $_column = $_itemColumn<String>('category_id')!;

    final manager = $$CategoriesCacheTableTableManager(
      $_db,
      $_db.categoriesCache,
    ).filter((f) => f.id.sqlEquals($_column));
    final item = $_typedResult.readTableOrNull(_categoryIdTable($_db));
    if (item == null) return manager;
    return ProcessedTableManager(
      manager.$state.copyWith(prefetchedData: [item]),
    );
  }

  static MultiTypedResultKey<
    $TransactionsLocalTable,
    List<TransactionsLocalData>
  >
  _transactionsLocalRefsTable(_$ClubBarDatabase db) =>
      MultiTypedResultKey.fromTable(
        db.transactionsLocal,
        aliasName: 'products_cache__id__transactions_local__product_id',
      );

  $$TransactionsLocalTableProcessedTableManager get transactionsLocalRefs {
    final manager = $$TransactionsLocalTableTableManager(
      $_db,
      $_db.transactionsLocal,
    ).filter((f) => f.productId.id.sqlEquals($_itemColumn<String>('id')!));

    final cache = $_typedResult.readTableOrNull(
      _transactionsLocalRefsTable($_db),
    );
    return ProcessedTableManager(
      manager.$state.copyWith(prefetchedData: cache),
    );
  }
}

class $$ProductsCacheTableFilterComposer
    extends Composer<_$ClubBarDatabase, $ProductsCacheTable> {
  $$ProductsCacheTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get names => $composableBuilder(
    column: $table.names,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get descriptions => $composableBuilder(
    column: $table.descriptions,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get priceCents => $composableBuilder(
    column: $table.priceCents,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get isActive => $composableBuilder(
    column: $table.isActive,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get requiresDispenser => $composableBuilder(
    column: $table.requiresDispenser,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get minAge => $composableBuilder(
    column: $table.minAge,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get iconName => $composableBuilder(
    column: $table.iconName,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get deletedAt => $composableBuilder(
    column: $table.deletedAt,
    builder: (column) => ColumnFilters(column),
  );

  $$CategoriesCacheTableFilterComposer get categoryId {
    final $$CategoriesCacheTableFilterComposer composer = $composerBuilder(
      composer: this,
      getCurrentColumn: (t) => t.categoryId,
      referencedTable: $db.categoriesCache,
      getReferencedColumn: (t) => t.id,
      builder:
          (
            joinBuilder, {
            $addJoinBuilderToRootComposer,
            $removeJoinBuilderFromRootComposer,
          }) => $$CategoriesCacheTableFilterComposer(
            $db: $db,
            $table: $db.categoriesCache,
            $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
            joinBuilder: joinBuilder,
            $removeJoinBuilderFromRootComposer:
                $removeJoinBuilderFromRootComposer,
          ),
    );
    return composer;
  }

  Expression<bool> transactionsLocalRefs(
    Expression<bool> Function($$TransactionsLocalTableFilterComposer f) f,
  ) {
    final $$TransactionsLocalTableFilterComposer composer = $composerBuilder(
      composer: this,
      getCurrentColumn: (t) => t.id,
      referencedTable: $db.transactionsLocal,
      getReferencedColumn: (t) => t.productId,
      builder:
          (
            joinBuilder, {
            $addJoinBuilderToRootComposer,
            $removeJoinBuilderFromRootComposer,
          }) => $$TransactionsLocalTableFilterComposer(
            $db: $db,
            $table: $db.transactionsLocal,
            $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
            joinBuilder: joinBuilder,
            $removeJoinBuilderFromRootComposer:
                $removeJoinBuilderFromRootComposer,
          ),
    );
    return f(composer);
  }
}

class $$ProductsCacheTableOrderingComposer
    extends Composer<_$ClubBarDatabase, $ProductsCacheTable> {
  $$ProductsCacheTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get names => $composableBuilder(
    column: $table.names,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get descriptions => $composableBuilder(
    column: $table.descriptions,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get priceCents => $composableBuilder(
    column: $table.priceCents,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get isActive => $composableBuilder(
    column: $table.isActive,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get requiresDispenser => $composableBuilder(
    column: $table.requiresDispenser,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get minAge => $composableBuilder(
    column: $table.minAge,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get iconName => $composableBuilder(
    column: $table.iconName,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get deletedAt => $composableBuilder(
    column: $table.deletedAt,
    builder: (column) => ColumnOrderings(column),
  );

  $$CategoriesCacheTableOrderingComposer get categoryId {
    final $$CategoriesCacheTableOrderingComposer composer = $composerBuilder(
      composer: this,
      getCurrentColumn: (t) => t.categoryId,
      referencedTable: $db.categoriesCache,
      getReferencedColumn: (t) => t.id,
      builder:
          (
            joinBuilder, {
            $addJoinBuilderToRootComposer,
            $removeJoinBuilderFromRootComposer,
          }) => $$CategoriesCacheTableOrderingComposer(
            $db: $db,
            $table: $db.categoriesCache,
            $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
            joinBuilder: joinBuilder,
            $removeJoinBuilderFromRootComposer:
                $removeJoinBuilderFromRootComposer,
          ),
    );
    return composer;
  }
}

class $$ProductsCacheTableAnnotationComposer
    extends Composer<_$ClubBarDatabase, $ProductsCacheTable> {
  $$ProductsCacheTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get names =>
      $composableBuilder(column: $table.names, builder: (column) => column);

  GeneratedColumn<String> get descriptions => $composableBuilder(
    column: $table.descriptions,
    builder: (column) => column,
  );

  GeneratedColumn<int> get priceCents => $composableBuilder(
    column: $table.priceCents,
    builder: (column) => column,
  );

  GeneratedColumn<int> get isActive =>
      $composableBuilder(column: $table.isActive, builder: (column) => column);

  GeneratedColumn<int> get requiresDispenser => $composableBuilder(
    column: $table.requiresDispenser,
    builder: (column) => column,
  );

  GeneratedColumn<int> get minAge =>
      $composableBuilder(column: $table.minAge, builder: (column) => column);

  GeneratedColumn<String> get iconName =>
      $composableBuilder(column: $table.iconName, builder: (column) => column);

  GeneratedColumn<String> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);

  GeneratedColumn<String> get deletedAt =>
      $composableBuilder(column: $table.deletedAt, builder: (column) => column);

  $$CategoriesCacheTableAnnotationComposer get categoryId {
    final $$CategoriesCacheTableAnnotationComposer composer = $composerBuilder(
      composer: this,
      getCurrentColumn: (t) => t.categoryId,
      referencedTable: $db.categoriesCache,
      getReferencedColumn: (t) => t.id,
      builder:
          (
            joinBuilder, {
            $addJoinBuilderToRootComposer,
            $removeJoinBuilderFromRootComposer,
          }) => $$CategoriesCacheTableAnnotationComposer(
            $db: $db,
            $table: $db.categoriesCache,
            $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
            joinBuilder: joinBuilder,
            $removeJoinBuilderFromRootComposer:
                $removeJoinBuilderFromRootComposer,
          ),
    );
    return composer;
  }

  Expression<T> transactionsLocalRefs<T extends Object>(
    Expression<T> Function($$TransactionsLocalTableAnnotationComposer a) f,
  ) {
    final $$TransactionsLocalTableAnnotationComposer composer =
        $composerBuilder(
          composer: this,
          getCurrentColumn: (t) => t.id,
          referencedTable: $db.transactionsLocal,
          getReferencedColumn: (t) => t.productId,
          builder:
              (
                joinBuilder, {
                $addJoinBuilderToRootComposer,
                $removeJoinBuilderFromRootComposer,
              }) => $$TransactionsLocalTableAnnotationComposer(
                $db: $db,
                $table: $db.transactionsLocal,
                $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
                joinBuilder: joinBuilder,
                $removeJoinBuilderFromRootComposer:
                    $removeJoinBuilderFromRootComposer,
              ),
        );
    return f(composer);
  }
}

class $$ProductsCacheTableTableManager
    extends
        RootTableManager<
          _$ClubBarDatabase,
          $ProductsCacheTable,
          ProductsCacheData,
          $$ProductsCacheTableFilterComposer,
          $$ProductsCacheTableOrderingComposer,
          $$ProductsCacheTableAnnotationComposer,
          $$ProductsCacheTableCreateCompanionBuilder,
          $$ProductsCacheTableUpdateCompanionBuilder,
          (ProductsCacheData, $$ProductsCacheTableReferences),
          ProductsCacheData,
          PrefetchHooks Function({bool categoryId, bool transactionsLocalRefs})
        > {
  $$ProductsCacheTableTableManager(
    _$ClubBarDatabase db,
    $ProductsCacheTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$ProductsCacheTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$ProductsCacheTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$ProductsCacheTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> categoryId = const Value.absent(),
                Value<String> names = const Value.absent(),
                Value<String?> descriptions = const Value.absent(),
                Value<int> priceCents = const Value.absent(),
                Value<int> isActive = const Value.absent(),
                Value<int> requiresDispenser = const Value.absent(),
                Value<int?> minAge = const Value.absent(),
                Value<String?> iconName = const Value.absent(),
                Value<String> updatedAt = const Value.absent(),
                Value<String?> deletedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => ProductsCacheCompanion(
                id: id,
                categoryId: categoryId,
                names: names,
                descriptions: descriptions,
                priceCents: priceCents,
                isActive: isActive,
                requiresDispenser: requiresDispenser,
                minAge: minAge,
                iconName: iconName,
                updatedAt: updatedAt,
                deletedAt: deletedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String categoryId,
                required String names,
                Value<String?> descriptions = const Value.absent(),
                required int priceCents,
                Value<int> isActive = const Value.absent(),
                Value<int> requiresDispenser = const Value.absent(),
                Value<int?> minAge = const Value.absent(),
                Value<String?> iconName = const Value.absent(),
                required String updatedAt,
                Value<String?> deletedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => ProductsCacheCompanion.insert(
                id: id,
                categoryId: categoryId,
                names: names,
                descriptions: descriptions,
                priceCents: priceCents,
                isActive: isActive,
                requiresDispenser: requiresDispenser,
                minAge: minAge,
                iconName: iconName,
                updatedAt: updatedAt,
                deletedAt: deletedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable(table),
                  $$ProductsCacheTableReferences(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback:
              ({categoryId = false, transactionsLocalRefs = false}) {
                return PrefetchHooks(
                  db: db,
                  explicitlyWatchedTables: [
                    if (transactionsLocalRefs) db.transactionsLocal,
                  ],
                  addJoins:
                      <
                        T extends TableManagerState<
                          dynamic,
                          dynamic,
                          dynamic,
                          dynamic,
                          dynamic,
                          dynamic,
                          dynamic,
                          dynamic,
                          dynamic,
                          dynamic,
                          dynamic
                        >
                      >(state) {
                        if (categoryId) {
                          state =
                              state.withJoin(
                                    currentTable: table,
                                    currentColumn: table.categoryId,
                                    referencedTable:
                                        $$ProductsCacheTableReferences
                                            ._categoryIdTable(db),
                                    referencedColumn:
                                        $$ProductsCacheTableReferences
                                            ._categoryIdTable(db)
                                            .id,
                                  )
                                  as T;
                        }

                        return state;
                      },
                  getPrefetchedDataCallback: (items) async {
                    return [
                      if (transactionsLocalRefs)
                        await $_getPrefetchedData<
                          ProductsCacheData,
                          $ProductsCacheTable,
                          TransactionsLocalData
                        >(
                          currentTable: table,
                          referencedTable: $$ProductsCacheTableReferences
                              ._transactionsLocalRefsTable(db),
                          managerFromTypedResult: (p0) =>
                              $$ProductsCacheTableReferences(
                                db,
                                table,
                                p0,
                              ).transactionsLocalRefs,
                          referencedItemsForCurrentItem:
                              (item, referencedItems) => referencedItems.where(
                                (e) => e.productId == item.id,
                              ),
                          typedResults: items,
                        ),
                    ];
                  },
                );
              },
        ),
      );
}

typedef $$ProductsCacheTableProcessedTableManager =
    ProcessedTableManager<
      _$ClubBarDatabase,
      $ProductsCacheTable,
      ProductsCacheData,
      $$ProductsCacheTableFilterComposer,
      $$ProductsCacheTableOrderingComposer,
      $$ProductsCacheTableAnnotationComposer,
      $$ProductsCacheTableCreateCompanionBuilder,
      $$ProductsCacheTableUpdateCompanionBuilder,
      (ProductsCacheData, $$ProductsCacheTableReferences),
      ProductsCacheData,
      PrefetchHooks Function({bool categoryId, bool transactionsLocalRefs})
    >;
typedef $$TransactionsLocalTableCreateCompanionBuilder =
    TransactionsLocalCompanion Function({
      required String id,
      required String memberId,
      Value<String?> productId,
      required int amountCents,
      required String transactionType,
      Value<String?> notes,
      required String createdAt,
      Value<int> synced,
      Value<String?> dispenserTxId,
      Value<int?> dispenserRequested,
      Value<int?> dispenserActual,
      Value<String?> sessionId,
      Value<int?> unitPriceCents,
      Value<String?> quarantinedAt,
      Value<String?> quarantineReason,
      Value<int> rowid,
    });
typedef $$TransactionsLocalTableUpdateCompanionBuilder =
    TransactionsLocalCompanion Function({
      Value<String> id,
      Value<String> memberId,
      Value<String?> productId,
      Value<int> amountCents,
      Value<String> transactionType,
      Value<String?> notes,
      Value<String> createdAt,
      Value<int> synced,
      Value<String?> dispenserTxId,
      Value<int?> dispenserRequested,
      Value<int?> dispenserActual,
      Value<String?> sessionId,
      Value<int?> unitPriceCents,
      Value<String?> quarantinedAt,
      Value<String?> quarantineReason,
      Value<int> rowid,
    });

final class $$TransactionsLocalTableReferences
    extends
        BaseReferences<
          _$ClubBarDatabase,
          $TransactionsLocalTable,
          TransactionsLocalData
        > {
  $$TransactionsLocalTableReferences(
    super.$_db,
    super.$_table,
    super.$_typedResult,
  );

  static $MembersCacheTable _memberIdTable(_$ClubBarDatabase db) => db
      .membersCache
      .createAlias('transactions_local__member_id__members_cache__id');

  $$MembersCacheTableProcessedTableManager get memberId {
    final $_column = $_itemColumn<String>('member_id')!;

    final manager = $$MembersCacheTableTableManager(
      $_db,
      $_db.membersCache,
    ).filter((f) => f.id.sqlEquals($_column));
    final item = $_typedResult.readTableOrNull(_memberIdTable($_db));
    if (item == null) return manager;
    return ProcessedTableManager(
      manager.$state.copyWith(prefetchedData: [item]),
    );
  }

  static $ProductsCacheTable _productIdTable(_$ClubBarDatabase db) => db
      .productsCache
      .createAlias('transactions_local__product_id__products_cache__id');

  $$ProductsCacheTableProcessedTableManager? get productId {
    final $_column = $_itemColumn<String>('product_id');
    if ($_column == null) return null;
    final manager = $$ProductsCacheTableTableManager(
      $_db,
      $_db.productsCache,
    ).filter((f) => f.id.sqlEquals($_column));
    final item = $_typedResult.readTableOrNull(_productIdTable($_db));
    if (item == null) return manager;
    return ProcessedTableManager(
      manager.$state.copyWith(prefetchedData: [item]),
    );
  }
}

class $$TransactionsLocalTableFilterComposer
    extends Composer<_$ClubBarDatabase, $TransactionsLocalTable> {
  $$TransactionsLocalTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get amountCents => $composableBuilder(
    column: $table.amountCents,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get transactionType => $composableBuilder(
    column: $table.transactionType,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get notes => $composableBuilder(
    column: $table.notes,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get synced => $composableBuilder(
    column: $table.synced,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get dispenserTxId => $composableBuilder(
    column: $table.dispenserTxId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get dispenserRequested => $composableBuilder(
    column: $table.dispenserRequested,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get dispenserActual => $composableBuilder(
    column: $table.dispenserActual,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get sessionId => $composableBuilder(
    column: $table.sessionId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get unitPriceCents => $composableBuilder(
    column: $table.unitPriceCents,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get quarantinedAt => $composableBuilder(
    column: $table.quarantinedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get quarantineReason => $composableBuilder(
    column: $table.quarantineReason,
    builder: (column) => ColumnFilters(column),
  );

  $$MembersCacheTableFilterComposer get memberId {
    final $$MembersCacheTableFilterComposer composer = $composerBuilder(
      composer: this,
      getCurrentColumn: (t) => t.memberId,
      referencedTable: $db.membersCache,
      getReferencedColumn: (t) => t.id,
      builder:
          (
            joinBuilder, {
            $addJoinBuilderToRootComposer,
            $removeJoinBuilderFromRootComposer,
          }) => $$MembersCacheTableFilterComposer(
            $db: $db,
            $table: $db.membersCache,
            $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
            joinBuilder: joinBuilder,
            $removeJoinBuilderFromRootComposer:
                $removeJoinBuilderFromRootComposer,
          ),
    );
    return composer;
  }

  $$ProductsCacheTableFilterComposer get productId {
    final $$ProductsCacheTableFilterComposer composer = $composerBuilder(
      composer: this,
      getCurrentColumn: (t) => t.productId,
      referencedTable: $db.productsCache,
      getReferencedColumn: (t) => t.id,
      builder:
          (
            joinBuilder, {
            $addJoinBuilderToRootComposer,
            $removeJoinBuilderFromRootComposer,
          }) => $$ProductsCacheTableFilterComposer(
            $db: $db,
            $table: $db.productsCache,
            $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
            joinBuilder: joinBuilder,
            $removeJoinBuilderFromRootComposer:
                $removeJoinBuilderFromRootComposer,
          ),
    );
    return composer;
  }
}

class $$TransactionsLocalTableOrderingComposer
    extends Composer<_$ClubBarDatabase, $TransactionsLocalTable> {
  $$TransactionsLocalTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get amountCents => $composableBuilder(
    column: $table.amountCents,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get transactionType => $composableBuilder(
    column: $table.transactionType,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get notes => $composableBuilder(
    column: $table.notes,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get synced => $composableBuilder(
    column: $table.synced,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get dispenserTxId => $composableBuilder(
    column: $table.dispenserTxId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get dispenserRequested => $composableBuilder(
    column: $table.dispenserRequested,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get dispenserActual => $composableBuilder(
    column: $table.dispenserActual,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get sessionId => $composableBuilder(
    column: $table.sessionId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get unitPriceCents => $composableBuilder(
    column: $table.unitPriceCents,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get quarantinedAt => $composableBuilder(
    column: $table.quarantinedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get quarantineReason => $composableBuilder(
    column: $table.quarantineReason,
    builder: (column) => ColumnOrderings(column),
  );

  $$MembersCacheTableOrderingComposer get memberId {
    final $$MembersCacheTableOrderingComposer composer = $composerBuilder(
      composer: this,
      getCurrentColumn: (t) => t.memberId,
      referencedTable: $db.membersCache,
      getReferencedColumn: (t) => t.id,
      builder:
          (
            joinBuilder, {
            $addJoinBuilderToRootComposer,
            $removeJoinBuilderFromRootComposer,
          }) => $$MembersCacheTableOrderingComposer(
            $db: $db,
            $table: $db.membersCache,
            $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
            joinBuilder: joinBuilder,
            $removeJoinBuilderFromRootComposer:
                $removeJoinBuilderFromRootComposer,
          ),
    );
    return composer;
  }

  $$ProductsCacheTableOrderingComposer get productId {
    final $$ProductsCacheTableOrderingComposer composer = $composerBuilder(
      composer: this,
      getCurrentColumn: (t) => t.productId,
      referencedTable: $db.productsCache,
      getReferencedColumn: (t) => t.id,
      builder:
          (
            joinBuilder, {
            $addJoinBuilderToRootComposer,
            $removeJoinBuilderFromRootComposer,
          }) => $$ProductsCacheTableOrderingComposer(
            $db: $db,
            $table: $db.productsCache,
            $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
            joinBuilder: joinBuilder,
            $removeJoinBuilderFromRootComposer:
                $removeJoinBuilderFromRootComposer,
          ),
    );
    return composer;
  }
}

class $$TransactionsLocalTableAnnotationComposer
    extends Composer<_$ClubBarDatabase, $TransactionsLocalTable> {
  $$TransactionsLocalTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<int> get amountCents => $composableBuilder(
    column: $table.amountCents,
    builder: (column) => column,
  );

  GeneratedColumn<String> get transactionType => $composableBuilder(
    column: $table.transactionType,
    builder: (column) => column,
  );

  GeneratedColumn<String> get notes =>
      $composableBuilder(column: $table.notes, builder: (column) => column);

  GeneratedColumn<String> get createdAt =>
      $composableBuilder(column: $table.createdAt, builder: (column) => column);

  GeneratedColumn<int> get synced =>
      $composableBuilder(column: $table.synced, builder: (column) => column);

  GeneratedColumn<String> get dispenserTxId => $composableBuilder(
    column: $table.dispenserTxId,
    builder: (column) => column,
  );

  GeneratedColumn<int> get dispenserRequested => $composableBuilder(
    column: $table.dispenserRequested,
    builder: (column) => column,
  );

  GeneratedColumn<int> get dispenserActual => $composableBuilder(
    column: $table.dispenserActual,
    builder: (column) => column,
  );

  GeneratedColumn<String> get sessionId =>
      $composableBuilder(column: $table.sessionId, builder: (column) => column);

  GeneratedColumn<int> get unitPriceCents => $composableBuilder(
    column: $table.unitPriceCents,
    builder: (column) => column,
  );

  GeneratedColumn<String> get quarantinedAt => $composableBuilder(
    column: $table.quarantinedAt,
    builder: (column) => column,
  );

  GeneratedColumn<String> get quarantineReason => $composableBuilder(
    column: $table.quarantineReason,
    builder: (column) => column,
  );

  $$MembersCacheTableAnnotationComposer get memberId {
    final $$MembersCacheTableAnnotationComposer composer = $composerBuilder(
      composer: this,
      getCurrentColumn: (t) => t.memberId,
      referencedTable: $db.membersCache,
      getReferencedColumn: (t) => t.id,
      builder:
          (
            joinBuilder, {
            $addJoinBuilderToRootComposer,
            $removeJoinBuilderFromRootComposer,
          }) => $$MembersCacheTableAnnotationComposer(
            $db: $db,
            $table: $db.membersCache,
            $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
            joinBuilder: joinBuilder,
            $removeJoinBuilderFromRootComposer:
                $removeJoinBuilderFromRootComposer,
          ),
    );
    return composer;
  }

  $$ProductsCacheTableAnnotationComposer get productId {
    final $$ProductsCacheTableAnnotationComposer composer = $composerBuilder(
      composer: this,
      getCurrentColumn: (t) => t.productId,
      referencedTable: $db.productsCache,
      getReferencedColumn: (t) => t.id,
      builder:
          (
            joinBuilder, {
            $addJoinBuilderToRootComposer,
            $removeJoinBuilderFromRootComposer,
          }) => $$ProductsCacheTableAnnotationComposer(
            $db: $db,
            $table: $db.productsCache,
            $addJoinBuilderToRootComposer: $addJoinBuilderToRootComposer,
            joinBuilder: joinBuilder,
            $removeJoinBuilderFromRootComposer:
                $removeJoinBuilderFromRootComposer,
          ),
    );
    return composer;
  }
}

class $$TransactionsLocalTableTableManager
    extends
        RootTableManager<
          _$ClubBarDatabase,
          $TransactionsLocalTable,
          TransactionsLocalData,
          $$TransactionsLocalTableFilterComposer,
          $$TransactionsLocalTableOrderingComposer,
          $$TransactionsLocalTableAnnotationComposer,
          $$TransactionsLocalTableCreateCompanionBuilder,
          $$TransactionsLocalTableUpdateCompanionBuilder,
          (TransactionsLocalData, $$TransactionsLocalTableReferences),
          TransactionsLocalData,
          PrefetchHooks Function({bool memberId, bool productId})
        > {
  $$TransactionsLocalTableTableManager(
    _$ClubBarDatabase db,
    $TransactionsLocalTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$TransactionsLocalTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$TransactionsLocalTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$TransactionsLocalTableAnnotationComposer(
                $db: db,
                $table: table,
              ),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> memberId = const Value.absent(),
                Value<String?> productId = const Value.absent(),
                Value<int> amountCents = const Value.absent(),
                Value<String> transactionType = const Value.absent(),
                Value<String?> notes = const Value.absent(),
                Value<String> createdAt = const Value.absent(),
                Value<int> synced = const Value.absent(),
                Value<String?> dispenserTxId = const Value.absent(),
                Value<int?> dispenserRequested = const Value.absent(),
                Value<int?> dispenserActual = const Value.absent(),
                Value<String?> sessionId = const Value.absent(),
                Value<int?> unitPriceCents = const Value.absent(),
                Value<String?> quarantinedAt = const Value.absent(),
                Value<String?> quarantineReason = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => TransactionsLocalCompanion(
                id: id,
                memberId: memberId,
                productId: productId,
                amountCents: amountCents,
                transactionType: transactionType,
                notes: notes,
                createdAt: createdAt,
                synced: synced,
                dispenserTxId: dispenserTxId,
                dispenserRequested: dispenserRequested,
                dispenserActual: dispenserActual,
                sessionId: sessionId,
                unitPriceCents: unitPriceCents,
                quarantinedAt: quarantinedAt,
                quarantineReason: quarantineReason,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String memberId,
                Value<String?> productId = const Value.absent(),
                required int amountCents,
                required String transactionType,
                Value<String?> notes = const Value.absent(),
                required String createdAt,
                Value<int> synced = const Value.absent(),
                Value<String?> dispenserTxId = const Value.absent(),
                Value<int?> dispenserRequested = const Value.absent(),
                Value<int?> dispenserActual = const Value.absent(),
                Value<String?> sessionId = const Value.absent(),
                Value<int?> unitPriceCents = const Value.absent(),
                Value<String?> quarantinedAt = const Value.absent(),
                Value<String?> quarantineReason = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => TransactionsLocalCompanion.insert(
                id: id,
                memberId: memberId,
                productId: productId,
                amountCents: amountCents,
                transactionType: transactionType,
                notes: notes,
                createdAt: createdAt,
                synced: synced,
                dispenserTxId: dispenserTxId,
                dispenserRequested: dispenserRequested,
                dispenserActual: dispenserActual,
                sessionId: sessionId,
                unitPriceCents: unitPriceCents,
                quarantinedAt: quarantinedAt,
                quarantineReason: quarantineReason,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable(table),
                  $$TransactionsLocalTableReferences(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: ({memberId = false, productId = false}) {
            return PrefetchHooks(
              db: db,
              explicitlyWatchedTables: [],
              addJoins:
                  <
                    T extends TableManagerState<
                      dynamic,
                      dynamic,
                      dynamic,
                      dynamic,
                      dynamic,
                      dynamic,
                      dynamic,
                      dynamic,
                      dynamic,
                      dynamic,
                      dynamic
                    >
                  >(state) {
                    if (memberId) {
                      state =
                          state.withJoin(
                                currentTable: table,
                                currentColumn: table.memberId,
                                referencedTable:
                                    $$TransactionsLocalTableReferences
                                        ._memberIdTable(db),
                                referencedColumn:
                                    $$TransactionsLocalTableReferences
                                        ._memberIdTable(db)
                                        .id,
                              )
                              as T;
                    }
                    if (productId) {
                      state =
                          state.withJoin(
                                currentTable: table,
                                currentColumn: table.productId,
                                referencedTable:
                                    $$TransactionsLocalTableReferences
                                        ._productIdTable(db),
                                referencedColumn:
                                    $$TransactionsLocalTableReferences
                                        ._productIdTable(db)
                                        .id,
                              )
                              as T;
                    }

                    return state;
                  },
              getPrefetchedDataCallback: (items) async {
                return [];
              },
            );
          },
        ),
      );
}

typedef $$TransactionsLocalTableProcessedTableManager =
    ProcessedTableManager<
      _$ClubBarDatabase,
      $TransactionsLocalTable,
      TransactionsLocalData,
      $$TransactionsLocalTableFilterComposer,
      $$TransactionsLocalTableOrderingComposer,
      $$TransactionsLocalTableAnnotationComposer,
      $$TransactionsLocalTableCreateCompanionBuilder,
      $$TransactionsLocalTableUpdateCompanionBuilder,
      (TransactionsLocalData, $$TransactionsLocalTableReferences),
      TransactionsLocalData,
      PrefetchHooks Function({bool memberId, bool productId})
    >;
typedef $$SyncStateTableCreateCompanionBuilder =
    SyncStateCompanion Function({
      required String key,
      required String value,
      Value<int> rowid,
    });
typedef $$SyncStateTableUpdateCompanionBuilder =
    SyncStateCompanion Function({
      Value<String> key,
      Value<String> value,
      Value<int> rowid,
    });

class $$SyncStateTableFilterComposer
    extends Composer<_$ClubBarDatabase, $SyncStateTable> {
  $$SyncStateTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get value => $composableBuilder(
    column: $table.value,
    builder: (column) => ColumnFilters(column),
  );
}

class $$SyncStateTableOrderingComposer
    extends Composer<_$ClubBarDatabase, $SyncStateTable> {
  $$SyncStateTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get value => $composableBuilder(
    column: $table.value,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$SyncStateTableAnnotationComposer
    extends Composer<_$ClubBarDatabase, $SyncStateTable> {
  $$SyncStateTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get key =>
      $composableBuilder(column: $table.key, builder: (column) => column);

  GeneratedColumn<String> get value =>
      $composableBuilder(column: $table.value, builder: (column) => column);
}

class $$SyncStateTableTableManager
    extends
        RootTableManager<
          _$ClubBarDatabase,
          $SyncStateTable,
          SyncStateData,
          $$SyncStateTableFilterComposer,
          $$SyncStateTableOrderingComposer,
          $$SyncStateTableAnnotationComposer,
          $$SyncStateTableCreateCompanionBuilder,
          $$SyncStateTableUpdateCompanionBuilder,
          (
            SyncStateData,
            BaseReferences<_$ClubBarDatabase, $SyncStateTable, SyncStateData>,
          ),
          SyncStateData,
          PrefetchHooks Function()
        > {
  $$SyncStateTableTableManager(_$ClubBarDatabase db, $SyncStateTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$SyncStateTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$SyncStateTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$SyncStateTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> key = const Value.absent(),
                Value<String> value = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => SyncStateCompanion(key: key, value: value, rowid: rowid),
          createCompanionCallback:
              ({
                required String key,
                required String value,
                Value<int> rowid = const Value.absent(),
              }) => SyncStateCompanion.insert(
                key: key,
                value: value,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$SyncStateTableProcessedTableManager =
    ProcessedTableManager<
      _$ClubBarDatabase,
      $SyncStateTable,
      SyncStateData,
      $$SyncStateTableFilterComposer,
      $$SyncStateTableOrderingComposer,
      $$SyncStateTableAnnotationComposer,
      $$SyncStateTableCreateCompanionBuilder,
      $$SyncStateTableUpdateCompanionBuilder,
      (
        SyncStateData,
        BaseReferences<_$ClubBarDatabase, $SyncStateTable, SyncStateData>,
      ),
      SyncStateData,
      PrefetchHooks Function()
    >;
typedef $$DispenserConfigTableCreateCompanionBuilder =
    DispenserConfigCompanion Function({
      required String key,
      required String value,
      Value<int> rowid,
    });
typedef $$DispenserConfigTableUpdateCompanionBuilder =
    DispenserConfigCompanion Function({
      Value<String> key,
      Value<String> value,
      Value<int> rowid,
    });

class $$DispenserConfigTableFilterComposer
    extends Composer<_$ClubBarDatabase, $DispenserConfigTable> {
  $$DispenserConfigTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get value => $composableBuilder(
    column: $table.value,
    builder: (column) => ColumnFilters(column),
  );
}

class $$DispenserConfigTableOrderingComposer
    extends Composer<_$ClubBarDatabase, $DispenserConfigTable> {
  $$DispenserConfigTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get value => $composableBuilder(
    column: $table.value,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$DispenserConfigTableAnnotationComposer
    extends Composer<_$ClubBarDatabase, $DispenserConfigTable> {
  $$DispenserConfigTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get key =>
      $composableBuilder(column: $table.key, builder: (column) => column);

  GeneratedColumn<String> get value =>
      $composableBuilder(column: $table.value, builder: (column) => column);
}

class $$DispenserConfigTableTableManager
    extends
        RootTableManager<
          _$ClubBarDatabase,
          $DispenserConfigTable,
          DispenserConfigData,
          $$DispenserConfigTableFilterComposer,
          $$DispenserConfigTableOrderingComposer,
          $$DispenserConfigTableAnnotationComposer,
          $$DispenserConfigTableCreateCompanionBuilder,
          $$DispenserConfigTableUpdateCompanionBuilder,
          (
            DispenserConfigData,
            BaseReferences<
              _$ClubBarDatabase,
              $DispenserConfigTable,
              DispenserConfigData
            >,
          ),
          DispenserConfigData,
          PrefetchHooks Function()
        > {
  $$DispenserConfigTableTableManager(
    _$ClubBarDatabase db,
    $DispenserConfigTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$DispenserConfigTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$DispenserConfigTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$DispenserConfigTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> key = const Value.absent(),
                Value<String> value = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => DispenserConfigCompanion(
                key: key,
                value: value,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String key,
                required String value,
                Value<int> rowid = const Value.absent(),
              }) => DispenserConfigCompanion.insert(
                key: key,
                value: value,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$DispenserConfigTableProcessedTableManager =
    ProcessedTableManager<
      _$ClubBarDatabase,
      $DispenserConfigTable,
      DispenserConfigData,
      $$DispenserConfigTableFilterComposer,
      $$DispenserConfigTableOrderingComposer,
      $$DispenserConfigTableAnnotationComposer,
      $$DispenserConfigTableCreateCompanionBuilder,
      $$DispenserConfigTableUpdateCompanionBuilder,
      (
        DispenserConfigData,
        BaseReferences<
          _$ClubBarDatabase,
          $DispenserConfigTable,
          DispenserConfigData
        >,
      ),
      DispenserConfigData,
      PrefetchHooks Function()
    >;
typedef $$DispenserOperationsTableCreateCompanionBuilder =
    DispenserOperationsCompanion Function({
      required String dispenserTxId,
      required String memberId,
      required String productId,
      required int priceCents,
      required int requestedQty,
      required String createdAt,
      Value<int> transactionsCreated,
      Value<String?> lastKnownState,
      Value<int> lastKnownDispensed,
      Value<String?> lastPolledAt,
      Value<int> pollingActive,
      Value<int> rowid,
    });
typedef $$DispenserOperationsTableUpdateCompanionBuilder =
    DispenserOperationsCompanion Function({
      Value<String> dispenserTxId,
      Value<String> memberId,
      Value<String> productId,
      Value<int> priceCents,
      Value<int> requestedQty,
      Value<String> createdAt,
      Value<int> transactionsCreated,
      Value<String?> lastKnownState,
      Value<int> lastKnownDispensed,
      Value<String?> lastPolledAt,
      Value<int> pollingActive,
      Value<int> rowid,
    });

class $$DispenserOperationsTableFilterComposer
    extends Composer<_$ClubBarDatabase, $DispenserOperationsTable> {
  $$DispenserOperationsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get dispenserTxId => $composableBuilder(
    column: $table.dispenserTxId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get memberId => $composableBuilder(
    column: $table.memberId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get productId => $composableBuilder(
    column: $table.productId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get priceCents => $composableBuilder(
    column: $table.priceCents,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get requestedQty => $composableBuilder(
    column: $table.requestedQty,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get transactionsCreated => $composableBuilder(
    column: $table.transactionsCreated,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get lastKnownState => $composableBuilder(
    column: $table.lastKnownState,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get lastKnownDispensed => $composableBuilder(
    column: $table.lastKnownDispensed,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get lastPolledAt => $composableBuilder(
    column: $table.lastPolledAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get pollingActive => $composableBuilder(
    column: $table.pollingActive,
    builder: (column) => ColumnFilters(column),
  );
}

class $$DispenserOperationsTableOrderingComposer
    extends Composer<_$ClubBarDatabase, $DispenserOperationsTable> {
  $$DispenserOperationsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get dispenserTxId => $composableBuilder(
    column: $table.dispenserTxId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get memberId => $composableBuilder(
    column: $table.memberId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get productId => $composableBuilder(
    column: $table.productId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get priceCents => $composableBuilder(
    column: $table.priceCents,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get requestedQty => $composableBuilder(
    column: $table.requestedQty,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get transactionsCreated => $composableBuilder(
    column: $table.transactionsCreated,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get lastKnownState => $composableBuilder(
    column: $table.lastKnownState,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get lastKnownDispensed => $composableBuilder(
    column: $table.lastKnownDispensed,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get lastPolledAt => $composableBuilder(
    column: $table.lastPolledAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get pollingActive => $composableBuilder(
    column: $table.pollingActive,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$DispenserOperationsTableAnnotationComposer
    extends Composer<_$ClubBarDatabase, $DispenserOperationsTable> {
  $$DispenserOperationsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get dispenserTxId => $composableBuilder(
    column: $table.dispenserTxId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get memberId =>
      $composableBuilder(column: $table.memberId, builder: (column) => column);

  GeneratedColumn<String> get productId =>
      $composableBuilder(column: $table.productId, builder: (column) => column);

  GeneratedColumn<int> get priceCents => $composableBuilder(
    column: $table.priceCents,
    builder: (column) => column,
  );

  GeneratedColumn<int> get requestedQty => $composableBuilder(
    column: $table.requestedQty,
    builder: (column) => column,
  );

  GeneratedColumn<String> get createdAt =>
      $composableBuilder(column: $table.createdAt, builder: (column) => column);

  GeneratedColumn<int> get transactionsCreated => $composableBuilder(
    column: $table.transactionsCreated,
    builder: (column) => column,
  );

  GeneratedColumn<String> get lastKnownState => $composableBuilder(
    column: $table.lastKnownState,
    builder: (column) => column,
  );

  GeneratedColumn<int> get lastKnownDispensed => $composableBuilder(
    column: $table.lastKnownDispensed,
    builder: (column) => column,
  );

  GeneratedColumn<String> get lastPolledAt => $composableBuilder(
    column: $table.lastPolledAt,
    builder: (column) => column,
  );

  GeneratedColumn<int> get pollingActive => $composableBuilder(
    column: $table.pollingActive,
    builder: (column) => column,
  );
}

class $$DispenserOperationsTableTableManager
    extends
        RootTableManager<
          _$ClubBarDatabase,
          $DispenserOperationsTable,
          DispenserOperation,
          $$DispenserOperationsTableFilterComposer,
          $$DispenserOperationsTableOrderingComposer,
          $$DispenserOperationsTableAnnotationComposer,
          $$DispenserOperationsTableCreateCompanionBuilder,
          $$DispenserOperationsTableUpdateCompanionBuilder,
          (
            DispenserOperation,
            BaseReferences<
              _$ClubBarDatabase,
              $DispenserOperationsTable,
              DispenserOperation
            >,
          ),
          DispenserOperation,
          PrefetchHooks Function()
        > {
  $$DispenserOperationsTableTableManager(
    _$ClubBarDatabase db,
    $DispenserOperationsTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$DispenserOperationsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$DispenserOperationsTableOrderingComposer(
                $db: db,
                $table: table,
              ),
          createComputedFieldComposer: () =>
              $$DispenserOperationsTableAnnotationComposer(
                $db: db,
                $table: table,
              ),
          updateCompanionCallback:
              ({
                Value<String> dispenserTxId = const Value.absent(),
                Value<String> memberId = const Value.absent(),
                Value<String> productId = const Value.absent(),
                Value<int> priceCents = const Value.absent(),
                Value<int> requestedQty = const Value.absent(),
                Value<String> createdAt = const Value.absent(),
                Value<int> transactionsCreated = const Value.absent(),
                Value<String?> lastKnownState = const Value.absent(),
                Value<int> lastKnownDispensed = const Value.absent(),
                Value<String?> lastPolledAt = const Value.absent(),
                Value<int> pollingActive = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => DispenserOperationsCompanion(
                dispenserTxId: dispenserTxId,
                memberId: memberId,
                productId: productId,
                priceCents: priceCents,
                requestedQty: requestedQty,
                createdAt: createdAt,
                transactionsCreated: transactionsCreated,
                lastKnownState: lastKnownState,
                lastKnownDispensed: lastKnownDispensed,
                lastPolledAt: lastPolledAt,
                pollingActive: pollingActive,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String dispenserTxId,
                required String memberId,
                required String productId,
                required int priceCents,
                required int requestedQty,
                required String createdAt,
                Value<int> transactionsCreated = const Value.absent(),
                Value<String?> lastKnownState = const Value.absent(),
                Value<int> lastKnownDispensed = const Value.absent(),
                Value<String?> lastPolledAt = const Value.absent(),
                Value<int> pollingActive = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => DispenserOperationsCompanion.insert(
                dispenserTxId: dispenserTxId,
                memberId: memberId,
                productId: productId,
                priceCents: priceCents,
                requestedQty: requestedQty,
                createdAt: createdAt,
                transactionsCreated: transactionsCreated,
                lastKnownState: lastKnownState,
                lastKnownDispensed: lastKnownDispensed,
                lastPolledAt: lastPolledAt,
                pollingActive: pollingActive,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$DispenserOperationsTableProcessedTableManager =
    ProcessedTableManager<
      _$ClubBarDatabase,
      $DispenserOperationsTable,
      DispenserOperation,
      $$DispenserOperationsTableFilterComposer,
      $$DispenserOperationsTableOrderingComposer,
      $$DispenserOperationsTableAnnotationComposer,
      $$DispenserOperationsTableCreateCompanionBuilder,
      $$DispenserOperationsTableUpdateCompanionBuilder,
      (
        DispenserOperation,
        BaseReferences<
          _$ClubBarDatabase,
          $DispenserOperationsTable,
          DispenserOperation
        >,
      ),
      DispenserOperation,
      PrefetchHooks Function()
    >;

class $ClubBarDatabaseManager {
  final _$ClubBarDatabase _db;
  $ClubBarDatabaseManager(this._db);
  $$MembersCacheTableTableManager get membersCache =>
      $$MembersCacheTableTableManager(_db, _db.membersCache);
  $$CategoriesCacheTableTableManager get categoriesCache =>
      $$CategoriesCacheTableTableManager(_db, _db.categoriesCache);
  $$ProductsCacheTableTableManager get productsCache =>
      $$ProductsCacheTableTableManager(_db, _db.productsCache);
  $$TransactionsLocalTableTableManager get transactionsLocal =>
      $$TransactionsLocalTableTableManager(_db, _db.transactionsLocal);
  $$SyncStateTableTableManager get syncState =>
      $$SyncStateTableTableManager(_db, _db.syncState);
  $$DispenserConfigTableTableManager get dispenserConfig =>
      $$DispenserConfigTableTableManager(_db, _db.dispenserConfig);
  $$DispenserOperationsTableTableManager get dispenserOperations =>
      $$DispenserOperationsTableTableManager(_db, _db.dispenserOperations);
}
