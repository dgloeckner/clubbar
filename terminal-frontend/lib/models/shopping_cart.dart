import 'package:flutter/foundation.dart';
import 'cart_item.dart';

class ShoppingCart extends ChangeNotifier {
  final List<CartItem> _items = [];

  List<CartItem> get items => List.unmodifiable(_items);

  int get itemCount => _items.fold<int>(0, (sum, item) => sum + item.quantity);

  int get totalCents => _items.fold<int>(0, (sum, item) => sum + item.lineTotalCents);

  void addItem(CartItem item) {
    final existingIndex = _items.indexWhere((i) => i.productId == item.productId);

    if (existingIndex >= 0) {
      _items[existingIndex].quantity += item.quantity;
    } else {
      _items.add(item);
    }
    notifyListeners();
  }

  void updateQuantity(String productId, int newQuantity) {
    final itemIndex = _items.indexWhere((i) => i.productId == productId);
    if (itemIndex >= 0) {
      if (newQuantity > 0) {
        _items[itemIndex].quantity = newQuantity;
      } else {
        _items.removeAt(itemIndex);
      }
      notifyListeners();
    }
  }

  void removeItem(String productId) {
    _items.removeWhere((item) => item.productId == productId);
    notifyListeners();
  }

  void clear() {
    _items.clear();
    notifyListeners();
  }

  bool get isEmpty => _items.isEmpty;
}
