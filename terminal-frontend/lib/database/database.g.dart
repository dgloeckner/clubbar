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
  @override
  List<GeneratedColumn> get $columns => [
    id,
    cardUid,
    firstName,
    lastName,
    preferredLanguage,
    isActive,
    isSepaValid,
    balanceCents,
    updatedAt,
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
  final String preferredLanguage;
  final int isActive;
  final int isSepaValid;
  final int balanceCents;
  final String updatedAt;
  const MembersCacheData({
    required this.id,
    this.cardUid,
    this.firstName,
    this.lastName,
    required this.preferredLanguage,
    required this.isActive,
    required this.isSepaValid,
    required this.balanceCents,
    required this.updatedAt,
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
    map['preferred_language'] = Variable<String>(preferredLanguage);
    map['is_active'] = Variable<int>(isActive);
    map['is_sepa_valid'] = Variable<int>(isSepaValid);
    map['balance_cents'] = Variable<int>(balanceCents);
    map['updated_at'] = Variable<String>(updatedAt);
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
      preferredLanguage: Value(preferredLanguage),
      isActive: Value(isActive),
      isSepaValid: Value(isSepaValid),
      balanceCents: Value(balanceCents),
      updatedAt: Value(updatedAt),
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
      preferredLanguage: serializer.fromJson<String>(json['preferredLanguage']),
      isActive: serializer.fromJson<int>(json['isActive']),
      isSepaValid: serializer.fromJson<int>(json['isSepaValid']),
      balanceCents: serializer.fromJson<int>(json['balanceCents']),
      updatedAt: serializer.fromJson<String>(json['updatedAt']),
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
      'preferredLanguage': serializer.toJson<String>(preferredLanguage),
      'isActive': serializer.toJson<int>(isActive),
      'isSepaValid': serializer.toJson<int>(isSepaValid),
      'balanceCents': serializer.toJson<int>(balanceCents),
      'updatedAt': serializer.toJson<String>(updatedAt),
    };
  }

  MembersCacheData copyWith({
    String? id,
    Value<String?> cardUid = const Value.absent(),
    Value<String?> firstName = const Value.absent(),
    Value<String?> lastName = const Value.absent(),
    String? preferredLanguage,
    int? isActive,
    int? isSepaValid,
    int? balanceCents,
    String? updatedAt,
  }) => MembersCacheData(
    id: id ?? this.id,
    cardUid: cardUid.present ? cardUid.value : this.cardUid,
    firstName: firstName.present ? firstName.value : this.firstName,
    lastName: lastName.present ? lastName.value : this.lastName,
    preferredLanguage: preferredLanguage ?? this.preferredLanguage,
    isActive: isActive ?? this.isActive,
    isSepaValid: isSepaValid ?? this.isSepaValid,
    balanceCents: balanceCents ?? this.balanceCents,
    updatedAt: updatedAt ?? this.updatedAt,
  );
  MembersCacheData copyWithCompanion(MembersCacheCompanion data) {
    return MembersCacheData(
      id: data.id.present ? data.id.value : this.id,
      cardUid: data.cardUid.present ? data.cardUid.value : this.cardUid,
      firstName: data.firstName.present ? data.firstName.value : this.firstName,
      lastName: data.lastName.present ? data.lastName.value : this.lastName,
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
    );
  }

  @override
  String toString() {
    return (StringBuffer('MembersCacheData(')
          ..write('id: $id, ')
          ..write('cardUid: $cardUid, ')
          ..write('firstName: $firstName, ')
          ..write('lastName: $lastName, ')
          ..write('preferredLanguage: $preferredLanguage, ')
          ..write('isActive: $isActive, ')
          ..write('isSepaValid: $isSepaValid, ')
          ..write('balanceCents: $balanceCents, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    cardUid,
    firstName,
    lastName,
    preferredLanguage,
    isActive,
    isSepaValid,
    balanceCents,
    updatedAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is MembersCacheData &&
          other.id == this.id &&
          other.cardUid == this.cardUid &&
          other.firstName == this.firstName &&
          other.lastName == this.lastName &&
          other.preferredLanguage == this.preferredLanguage &&
          other.isActive == this.isActive &&
          other.isSepaValid == this.isSepaValid &&
          other.balanceCents == this.balanceCents &&
          other.updatedAt == this.updatedAt);
}

class MembersCacheCompanion extends UpdateCompanion<MembersCacheData> {
  final Value<String> id;
  final Value<String?> cardUid;
  final Value<String?> firstName;
  final Value<String?> lastName;
  final Value<String> preferredLanguage;
  final Value<int> isActive;
  final Value<int> isSepaValid;
  final Value<int> balanceCents;
  final Value<String> updatedAt;
  final Value<int> rowid;
  const MembersCacheCompanion({
    this.id = const Value.absent(),
    this.cardUid = const Value.absent(),
    this.firstName = const Value.absent(),
    this.lastName = const Value.absent(),
    this.preferredLanguage = const Value.absent(),
    this.isActive = const Value.absent(),
    this.isSepaValid = const Value.absent(),
    this.balanceCents = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  MembersCacheCompanion.insert({
    required String id,
    this.cardUid = const Value.absent(),
    this.firstName = const Value.absent(),
    this.lastName = const Value.absent(),
    required String preferredLanguage,
    this.isActive = const Value.absent(),
    required int isSepaValid,
    this.balanceCents = const Value.absent(),
    required String updatedAt,
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
    Expression<String>? preferredLanguage,
    Expression<int>? isActive,
    Expression<int>? isSepaValid,
    Expression<int>? balanceCents,
    Expression<String>? updatedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (cardUid != null) 'card_uid': cardUid,
      if (firstName != null) 'first_name': firstName,
      if (lastName != null) 'last_name': lastName,
      if (preferredLanguage != null) 'preferred_language': preferredLanguage,
      if (isActive != null) 'is_active': isActive,
      if (isSepaValid != null) 'is_sepa_valid': isSepaValid,
      if (balanceCents != null) 'balance_cents': balanceCents,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  MembersCacheCompanion copyWith({
    Value<String>? id,
    Value<String?>? cardUid,
    Value<String?>? firstName,
    Value<String?>? lastName,
    Value<String>? preferredLanguage,
    Value<int>? isActive,
    Value<int>? isSepaValid,
    Value<int>? balanceCents,
    Value<String>? updatedAt,
    Value<int>? rowid,
  }) {
    return MembersCacheCompanion(
      id: id ?? this.id,
      cardUid: cardUid ?? this.cardUid,
      firstName: firstName ?? this.firstName,
      lastName: lastName ?? this.lastName,
      preferredLanguage: preferredLanguage ?? this.preferredLanguage,
      isActive: isActive ?? this.isActive,
      isSepaValid: isSepaValid ?? this.isSepaValid,
      balanceCents: balanceCents ?? this.balanceCents,
      updatedAt: updatedAt ?? this.updatedAt,
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
          ..write('preferredLanguage: $preferredLanguage, ')
          ..write('isActive: $isActive, ')
          ..write('isSepaValid: $isSepaValid, ')
          ..write('balanceCents: $balanceCents, ')
          ..write('updatedAt: $updatedAt, ')
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
  static const VerificationMeta _displayOrderMeta = const VerificationMeta(
    'displayOrder',
  );
  @override
  late final GeneratedColumn<int> displayOrder = GeneratedColumn<int>(
    'display_order',
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
  @override
  List<GeneratedColumn> get $columns => [
    id,
    names,
    displayOrder,
    isActive,
    iconName,
    updatedAt,
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
    if (data.containsKey('display_order')) {
      context.handle(
        _displayOrderMeta,
        displayOrder.isAcceptableOrUnknown(
          data['display_order']!,
          _displayOrderMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_displayOrderMeta);
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
      displayOrder: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}display_order'],
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
  final int displayOrder;
  final int isActive;
  final String? iconName;
  final String updatedAt;
  const CategoriesCacheData({
    required this.id,
    required this.names,
    required this.displayOrder,
    required this.isActive,
    this.iconName,
    required this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['names'] = Variable<String>(names);
    map['display_order'] = Variable<int>(displayOrder);
    map['is_active'] = Variable<int>(isActive);
    if (!nullToAbsent || iconName != null) {
      map['icon_name'] = Variable<String>(iconName);
    }
    map['updated_at'] = Variable<String>(updatedAt);
    return map;
  }

  CategoriesCacheCompanion toCompanion(bool nullToAbsent) {
    return CategoriesCacheCompanion(
      id: Value(id),
      names: Value(names),
      displayOrder: Value(displayOrder),
      isActive: Value(isActive),
      iconName: iconName == null && nullToAbsent
          ? const Value.absent()
          : Value(iconName),
      updatedAt: Value(updatedAt),
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
      displayOrder: serializer.fromJson<int>(json['displayOrder']),
      isActive: serializer.fromJson<int>(json['isActive']),
      iconName: serializer.fromJson<String?>(json['iconName']),
      updatedAt: serializer.fromJson<String>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'names': serializer.toJson<String>(names),
      'displayOrder': serializer.toJson<int>(displayOrder),
      'isActive': serializer.toJson<int>(isActive),
      'iconName': serializer.toJson<String?>(iconName),
      'updatedAt': serializer.toJson<String>(updatedAt),
    };
  }

  CategoriesCacheData copyWith({
    String? id,
    String? names,
    int? displayOrder,
    int? isActive,
    Value<String?> iconName = const Value.absent(),
    String? updatedAt,
  }) => CategoriesCacheData(
    id: id ?? this.id,
    names: names ?? this.names,
    displayOrder: displayOrder ?? this.displayOrder,
    isActive: isActive ?? this.isActive,
    iconName: iconName.present ? iconName.value : this.iconName,
    updatedAt: updatedAt ?? this.updatedAt,
  );
  CategoriesCacheData copyWithCompanion(CategoriesCacheCompanion data) {
    return CategoriesCacheData(
      id: data.id.present ? data.id.value : this.id,
      names: data.names.present ? data.names.value : this.names,
      displayOrder: data.displayOrder.present
          ? data.displayOrder.value
          : this.displayOrder,
      isActive: data.isActive.present ? data.isActive.value : this.isActive,
      iconName: data.iconName.present ? data.iconName.value : this.iconName,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('CategoriesCacheData(')
          ..write('id: $id, ')
          ..write('names: $names, ')
          ..write('displayOrder: $displayOrder, ')
          ..write('isActive: $isActive, ')
          ..write('iconName: $iconName, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode =>
      Object.hash(id, names, displayOrder, isActive, iconName, updatedAt);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is CategoriesCacheData &&
          other.id == this.id &&
          other.names == this.names &&
          other.displayOrder == this.displayOrder &&
          other.isActive == this.isActive &&
          other.iconName == this.iconName &&
          other.updatedAt == this.updatedAt);
}

class CategoriesCacheCompanion extends UpdateCompanion<CategoriesCacheData> {
  final Value<String> id;
  final Value<String> names;
  final Value<int> displayOrder;
  final Value<int> isActive;
  final Value<String?> iconName;
  final Value<String> updatedAt;
  final Value<int> rowid;
  const CategoriesCacheCompanion({
    this.id = const Value.absent(),
    this.names = const Value.absent(),
    this.displayOrder = const Value.absent(),
    this.isActive = const Value.absent(),
    this.iconName = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  CategoriesCacheCompanion.insert({
    required String id,
    required String names,
    required int displayOrder,
    this.isActive = const Value.absent(),
    this.iconName = const Value.absent(),
    required String updatedAt,
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       names = Value(names),
       displayOrder = Value(displayOrder),
       updatedAt = Value(updatedAt);
  static Insertable<CategoriesCacheData> custom({
    Expression<String>? id,
    Expression<String>? names,
    Expression<int>? displayOrder,
    Expression<int>? isActive,
    Expression<String>? iconName,
    Expression<String>? updatedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (names != null) 'names': names,
      if (displayOrder != null) 'display_order': displayOrder,
      if (isActive != null) 'is_active': isActive,
      if (iconName != null) 'icon_name': iconName,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  CategoriesCacheCompanion copyWith({
    Value<String>? id,
    Value<String>? names,
    Value<int>? displayOrder,
    Value<int>? isActive,
    Value<String?>? iconName,
    Value<String>? updatedAt,
    Value<int>? rowid,
  }) {
    return CategoriesCacheCompanion(
      id: id ?? this.id,
      names: names ?? this.names,
      displayOrder: displayOrder ?? this.displayOrder,
      isActive: isActive ?? this.isActive,
      iconName: iconName ?? this.iconName,
      updatedAt: updatedAt ?? this.updatedAt,
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
    if (displayOrder.present) {
      map['display_order'] = Variable<int>(displayOrder.value);
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
          ..write('displayOrder: $displayOrder, ')
          ..write('isActive: $isActive, ')
          ..write('iconName: $iconName, ')
          ..write('updatedAt: $updatedAt, ')
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
  @override
  List<GeneratedColumn> get $columns => [
    id,
    categoryId,
    names,
    descriptions,
    priceCents,
    isActive,
    iconName,
    updatedAt,
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
      iconName: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}icon_name'],
      ),
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}updated_at'],
      )!,
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
  final String? iconName;
  final String updatedAt;
  const ProductsCacheData({
    required this.id,
    required this.categoryId,
    required this.names,
    this.descriptions,
    required this.priceCents,
    required this.isActive,
    this.iconName,
    required this.updatedAt,
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
    if (!nullToAbsent || iconName != null) {
      map['icon_name'] = Variable<String>(iconName);
    }
    map['updated_at'] = Variable<String>(updatedAt);
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
      iconName: iconName == null && nullToAbsent
          ? const Value.absent()
          : Value(iconName),
      updatedAt: Value(updatedAt),
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
      iconName: serializer.fromJson<String?>(json['iconName']),
      updatedAt: serializer.fromJson<String>(json['updatedAt']),
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
      'iconName': serializer.toJson<String?>(iconName),
      'updatedAt': serializer.toJson<String>(updatedAt),
    };
  }

  ProductsCacheData copyWith({
    String? id,
    String? categoryId,
    String? names,
    Value<String?> descriptions = const Value.absent(),
    int? priceCents,
    int? isActive,
    Value<String?> iconName = const Value.absent(),
    String? updatedAt,
  }) => ProductsCacheData(
    id: id ?? this.id,
    categoryId: categoryId ?? this.categoryId,
    names: names ?? this.names,
    descriptions: descriptions.present ? descriptions.value : this.descriptions,
    priceCents: priceCents ?? this.priceCents,
    isActive: isActive ?? this.isActive,
    iconName: iconName.present ? iconName.value : this.iconName,
    updatedAt: updatedAt ?? this.updatedAt,
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
      iconName: data.iconName.present ? data.iconName.value : this.iconName,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
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
          ..write('iconName: $iconName, ')
          ..write('updatedAt: $updatedAt')
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
    iconName,
    updatedAt,
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
          other.iconName == this.iconName &&
          other.updatedAt == this.updatedAt);
}

class ProductsCacheCompanion extends UpdateCompanion<ProductsCacheData> {
  final Value<String> id;
  final Value<String> categoryId;
  final Value<String> names;
  final Value<String?> descriptions;
  final Value<int> priceCents;
  final Value<int> isActive;
  final Value<String?> iconName;
  final Value<String> updatedAt;
  final Value<int> rowid;
  const ProductsCacheCompanion({
    this.id = const Value.absent(),
    this.categoryId = const Value.absent(),
    this.names = const Value.absent(),
    this.descriptions = const Value.absent(),
    this.priceCents = const Value.absent(),
    this.isActive = const Value.absent(),
    this.iconName = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  ProductsCacheCompanion.insert({
    required String id,
    required String categoryId,
    required String names,
    this.descriptions = const Value.absent(),
    required int priceCents,
    this.isActive = const Value.absent(),
    this.iconName = const Value.absent(),
    required String updatedAt,
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
    Expression<String>? iconName,
    Expression<String>? updatedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (categoryId != null) 'category_id': categoryId,
      if (names != null) 'names': names,
      if (descriptions != null) 'descriptions': descriptions,
      if (priceCents != null) 'price_cents': priceCents,
      if (isActive != null) 'is_active': isActive,
      if (iconName != null) 'icon_name': iconName,
      if (updatedAt != null) 'updated_at': updatedAt,
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
    Value<String?>? iconName,
    Value<String>? updatedAt,
    Value<int>? rowid,
  }) {
    return ProductsCacheCompanion(
      id: id ?? this.id,
      categoryId: categoryId ?? this.categoryId,
      names: names ?? this.names,
      descriptions: descriptions ?? this.descriptions,
      priceCents: priceCents ?? this.priceCents,
      isActive: isActive ?? this.isActive,
      iconName: iconName ?? this.iconName,
      updatedAt: updatedAt ?? this.updatedAt,
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
    if (iconName.present) {
      map['icon_name'] = Variable<String>(iconName.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<String>(updatedAt.value);
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
          ..write('iconName: $iconName, ')
          ..write('updatedAt: $updatedAt, ')
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
  const TransactionsLocalData({
    required this.id,
    required this.memberId,
    this.productId,
    required this.amountCents,
    required this.transactionType,
    this.notes,
    required this.createdAt,
    required this.synced,
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
  }) => TransactionsLocalData(
    id: id ?? this.id,
    memberId: memberId ?? this.memberId,
    productId: productId.present ? productId.value : this.productId,
    amountCents: amountCents ?? this.amountCents,
    transactionType: transactionType ?? this.transactionType,
    notes: notes.present ? notes.value : this.notes,
    createdAt: createdAt ?? this.createdAt,
    synced: synced ?? this.synced,
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
          ..write('synced: $synced')
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
          other.synced == this.synced);
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

abstract class _$RuderbarDatabase extends GeneratedDatabase {
  _$RuderbarDatabase(QueryExecutor e) : super(e);
  $RuderbarDatabaseManager get managers => $RuderbarDatabaseManager(this);
  late final $MembersCacheTable membersCache = $MembersCacheTable(this);
  late final $CategoriesCacheTable categoriesCache = $CategoriesCacheTable(
    this,
  );
  late final $ProductsCacheTable productsCache = $ProductsCacheTable(this);
  late final $TransactionsLocalTable transactionsLocal =
      $TransactionsLocalTable(this);
  late final $SyncStateTable syncState = $SyncStateTable(this);
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
  ];
}

typedef $$MembersCacheTableCreateCompanionBuilder =
    MembersCacheCompanion Function({
      required String id,
      Value<String?> cardUid,
      Value<String?> firstName,
      Value<String?> lastName,
      required String preferredLanguage,
      Value<int> isActive,
      required int isSepaValid,
      Value<int> balanceCents,
      required String updatedAt,
      Value<int> rowid,
    });
typedef $$MembersCacheTableUpdateCompanionBuilder =
    MembersCacheCompanion Function({
      Value<String> id,
      Value<String?> cardUid,
      Value<String?> firstName,
      Value<String?> lastName,
      Value<String> preferredLanguage,
      Value<int> isActive,
      Value<int> isSepaValid,
      Value<int> balanceCents,
      Value<String> updatedAt,
      Value<int> rowid,
    });

final class $$MembersCacheTableReferences
    extends
        BaseReferences<
          _$RuderbarDatabase,
          $MembersCacheTable,
          MembersCacheData
        > {
  $$MembersCacheTableReferences(super.$_db, super.$_table, super.$_typedResult);

  static MultiTypedResultKey<
    $TransactionsLocalTable,
    List<TransactionsLocalData>
  >
  _transactionsLocalRefsTable(_$RuderbarDatabase db) =>
      MultiTypedResultKey.fromTable(
        db.transactionsLocal,
        aliasName: $_aliasNameGenerator(
          db.membersCache.id,
          db.transactionsLocal.memberId,
        ),
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
    extends Composer<_$RuderbarDatabase, $MembersCacheTable> {
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
    extends Composer<_$RuderbarDatabase, $MembersCacheTable> {
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
}

class $$MembersCacheTableAnnotationComposer
    extends Composer<_$RuderbarDatabase, $MembersCacheTable> {
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
          _$RuderbarDatabase,
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
    _$RuderbarDatabase db,
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
                Value<String> preferredLanguage = const Value.absent(),
                Value<int> isActive = const Value.absent(),
                Value<int> isSepaValid = const Value.absent(),
                Value<int> balanceCents = const Value.absent(),
                Value<String> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => MembersCacheCompanion(
                id: id,
                cardUid: cardUid,
                firstName: firstName,
                lastName: lastName,
                preferredLanguage: preferredLanguage,
                isActive: isActive,
                isSepaValid: isSepaValid,
                balanceCents: balanceCents,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                Value<String?> cardUid = const Value.absent(),
                Value<String?> firstName = const Value.absent(),
                Value<String?> lastName = const Value.absent(),
                required String preferredLanguage,
                Value<int> isActive = const Value.absent(),
                required int isSepaValid,
                Value<int> balanceCents = const Value.absent(),
                required String updatedAt,
                Value<int> rowid = const Value.absent(),
              }) => MembersCacheCompanion.insert(
                id: id,
                cardUid: cardUid,
                firstName: firstName,
                lastName: lastName,
                preferredLanguage: preferredLanguage,
                isActive: isActive,
                isSepaValid: isSepaValid,
                balanceCents: balanceCents,
                updatedAt: updatedAt,
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
      _$RuderbarDatabase,
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
      required int displayOrder,
      Value<int> isActive,
      Value<String?> iconName,
      required String updatedAt,
      Value<int> rowid,
    });
typedef $$CategoriesCacheTableUpdateCompanionBuilder =
    CategoriesCacheCompanion Function({
      Value<String> id,
      Value<String> names,
      Value<int> displayOrder,
      Value<int> isActive,
      Value<String?> iconName,
      Value<String> updatedAt,
      Value<int> rowid,
    });

final class $$CategoriesCacheTableReferences
    extends
        BaseReferences<
          _$RuderbarDatabase,
          $CategoriesCacheTable,
          CategoriesCacheData
        > {
  $$CategoriesCacheTableReferences(
    super.$_db,
    super.$_table,
    super.$_typedResult,
  );

  static MultiTypedResultKey<$ProductsCacheTable, List<ProductsCacheData>>
  _productsCacheRefsTable(_$RuderbarDatabase db) =>
      MultiTypedResultKey.fromTable(
        db.productsCache,
        aliasName: $_aliasNameGenerator(
          db.categoriesCache.id,
          db.productsCache.categoryId,
        ),
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
    extends Composer<_$RuderbarDatabase, $CategoriesCacheTable> {
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

  ColumnFilters<int> get displayOrder => $composableBuilder(
    column: $table.displayOrder,
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
    extends Composer<_$RuderbarDatabase, $CategoriesCacheTable> {
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

  ColumnOrderings<int> get displayOrder => $composableBuilder(
    column: $table.displayOrder,
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
}

class $$CategoriesCacheTableAnnotationComposer
    extends Composer<_$RuderbarDatabase, $CategoriesCacheTable> {
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

  GeneratedColumn<int> get displayOrder => $composableBuilder(
    column: $table.displayOrder,
    builder: (column) => column,
  );

  GeneratedColumn<int> get isActive =>
      $composableBuilder(column: $table.isActive, builder: (column) => column);

  GeneratedColumn<String> get iconName =>
      $composableBuilder(column: $table.iconName, builder: (column) => column);

  GeneratedColumn<String> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);

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
          _$RuderbarDatabase,
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
    _$RuderbarDatabase db,
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
                Value<int> displayOrder = const Value.absent(),
                Value<int> isActive = const Value.absent(),
                Value<String?> iconName = const Value.absent(),
                Value<String> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => CategoriesCacheCompanion(
                id: id,
                names: names,
                displayOrder: displayOrder,
                isActive: isActive,
                iconName: iconName,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String names,
                required int displayOrder,
                Value<int> isActive = const Value.absent(),
                Value<String?> iconName = const Value.absent(),
                required String updatedAt,
                Value<int> rowid = const Value.absent(),
              }) => CategoriesCacheCompanion.insert(
                id: id,
                names: names,
                displayOrder: displayOrder,
                isActive: isActive,
                iconName: iconName,
                updatedAt: updatedAt,
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
      _$RuderbarDatabase,
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
      Value<String?> iconName,
      required String updatedAt,
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
      Value<String?> iconName,
      Value<String> updatedAt,
      Value<int> rowid,
    });

final class $$ProductsCacheTableReferences
    extends
        BaseReferences<
          _$RuderbarDatabase,
          $ProductsCacheTable,
          ProductsCacheData
        > {
  $$ProductsCacheTableReferences(
    super.$_db,
    super.$_table,
    super.$_typedResult,
  );

  static $CategoriesCacheTable _categoryIdTable(_$RuderbarDatabase db) =>
      db.categoriesCache.createAlias(
        $_aliasNameGenerator(
          db.productsCache.categoryId,
          db.categoriesCache.id,
        ),
      );

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
  _transactionsLocalRefsTable(_$RuderbarDatabase db) =>
      MultiTypedResultKey.fromTable(
        db.transactionsLocal,
        aliasName: $_aliasNameGenerator(
          db.productsCache.id,
          db.transactionsLocal.productId,
        ),
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
    extends Composer<_$RuderbarDatabase, $ProductsCacheTable> {
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

  ColumnFilters<String> get iconName => $composableBuilder(
    column: $table.iconName,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
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
    extends Composer<_$RuderbarDatabase, $ProductsCacheTable> {
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

  ColumnOrderings<String> get iconName => $composableBuilder(
    column: $table.iconName,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
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
    extends Composer<_$RuderbarDatabase, $ProductsCacheTable> {
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

  GeneratedColumn<String> get iconName =>
      $composableBuilder(column: $table.iconName, builder: (column) => column);

  GeneratedColumn<String> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);

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
          _$RuderbarDatabase,
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
    _$RuderbarDatabase db,
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
                Value<String?> iconName = const Value.absent(),
                Value<String> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => ProductsCacheCompanion(
                id: id,
                categoryId: categoryId,
                names: names,
                descriptions: descriptions,
                priceCents: priceCents,
                isActive: isActive,
                iconName: iconName,
                updatedAt: updatedAt,
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
                Value<String?> iconName = const Value.absent(),
                required String updatedAt,
                Value<int> rowid = const Value.absent(),
              }) => ProductsCacheCompanion.insert(
                id: id,
                categoryId: categoryId,
                names: names,
                descriptions: descriptions,
                priceCents: priceCents,
                isActive: isActive,
                iconName: iconName,
                updatedAt: updatedAt,
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
      _$RuderbarDatabase,
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
      Value<int> rowid,
    });

final class $$TransactionsLocalTableReferences
    extends
        BaseReferences<
          _$RuderbarDatabase,
          $TransactionsLocalTable,
          TransactionsLocalData
        > {
  $$TransactionsLocalTableReferences(
    super.$_db,
    super.$_table,
    super.$_typedResult,
  );

  static $MembersCacheTable _memberIdTable(_$RuderbarDatabase db) =>
      db.membersCache.createAlias(
        $_aliasNameGenerator(db.transactionsLocal.memberId, db.membersCache.id),
      );

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

  static $ProductsCacheTable _productIdTable(_$RuderbarDatabase db) =>
      db.productsCache.createAlias(
        $_aliasNameGenerator(
          db.transactionsLocal.productId,
          db.productsCache.id,
        ),
      );

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
    extends Composer<_$RuderbarDatabase, $TransactionsLocalTable> {
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
    extends Composer<_$RuderbarDatabase, $TransactionsLocalTable> {
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
    extends Composer<_$RuderbarDatabase, $TransactionsLocalTable> {
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
          _$RuderbarDatabase,
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
    _$RuderbarDatabase db,
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
      _$RuderbarDatabase,
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
    extends Composer<_$RuderbarDatabase, $SyncStateTable> {
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
    extends Composer<_$RuderbarDatabase, $SyncStateTable> {
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
    extends Composer<_$RuderbarDatabase, $SyncStateTable> {
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
          _$RuderbarDatabase,
          $SyncStateTable,
          SyncStateData,
          $$SyncStateTableFilterComposer,
          $$SyncStateTableOrderingComposer,
          $$SyncStateTableAnnotationComposer,
          $$SyncStateTableCreateCompanionBuilder,
          $$SyncStateTableUpdateCompanionBuilder,
          (
            SyncStateData,
            BaseReferences<_$RuderbarDatabase, $SyncStateTable, SyncStateData>,
          ),
          SyncStateData,
          PrefetchHooks Function()
        > {
  $$SyncStateTableTableManager(_$RuderbarDatabase db, $SyncStateTable table)
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
      _$RuderbarDatabase,
      $SyncStateTable,
      SyncStateData,
      $$SyncStateTableFilterComposer,
      $$SyncStateTableOrderingComposer,
      $$SyncStateTableAnnotationComposer,
      $$SyncStateTableCreateCompanionBuilder,
      $$SyncStateTableUpdateCompanionBuilder,
      (
        SyncStateData,
        BaseReferences<_$RuderbarDatabase, $SyncStateTable, SyncStateData>,
      ),
      SyncStateData,
      PrefetchHooks Function()
    >;

class $RuderbarDatabaseManager {
  final _$RuderbarDatabase _db;
  $RuderbarDatabaseManager(this._db);
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
}
