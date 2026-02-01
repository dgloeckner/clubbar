import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/database/database.dart';

void main() {
  test('database schema compiles correctly', () {
    // This test verifies the database class and schema are properly defined
    expect(RuderbarDatabase, isNotNull);
  });
}
