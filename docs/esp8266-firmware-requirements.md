# ESP8266 Token Dispenser Firmware Requirements

## Critical: State Persistence for Crash Recovery

The ESP8266 firmware **MUST** persist transaction state to EEPROM/flash storage to enable crash recovery.

### Problem Scenario

Without state persistence:
1. Terminal creates tracking record (requested: 3 tokens)
2. ESP8266 starts dispensing (dispenses 1 token)
3. **ESP8266 crashes** (power loss, firmware crash)
4. ESP8266 reboots with no memory of transaction
5. Terminal queries ESP8266 → 404 Not Found
6. **User got 1 token but wasn't charged** → Loss for club

### Solution: EEPROM State Persistence

#### Required EEPROM Fields

```c
typedef struct {
  char txId[17];        // 16-char hex transaction ID + null terminator
  uint8_t quantity;     // Requested token count
  uint8_t dispensed;    // Actually dispensed count
  uint8_t state;        // 0=idle, 1=dispensing, 2=done, 3=error
  uint32_t timestamp;   // Unix timestamp when started
} Transaction;
```

#### Firmware Implementation Checklist

**Before starting dispense:**
```c
void startDispense(const char* txId, uint8_t quantity) {
  Transaction tx;
  strncpy(tx.txId, txId, 16);
  tx.quantity = quantity;
  tx.dispensed = 0;
  tx.state = STATE_DISPENSING;
  tx.timestamp = millis() / 1000;
  
  EEPROM_writeTransaction(&tx);  // ✅ Write to EEPROM FIRST
  EEPROM.commit();
  
  // Now start dispensing
  startMotor();
}
```

**After each token dispensed:**
```c
void onTokenDispensed() {
  currentTransaction.dispensed++;
  EEPROM_updateDispensedCount(currentTransaction.txId, currentTransaction.dispensed);
  EEPROM.commit();  // ✅ Persist immediately
}
```

**On completion:**
```c
void onDispenseComplete() {
  currentTransaction.state = STATE_DONE;
  EEPROM_updateState(currentTransaction.txId, STATE_DONE);
  EEPROM.commit();
}
```

**On error (jam, timeout):**
```c
void onDispenseError() {
  currentTransaction.state = STATE_ERROR;
  EEPROM_updateState(currentTransaction.txId, STATE_ERROR);
  EEPROM.commit();
}
```

**On boot (crash recovery):**
```c
void setup() {
  // Check for incomplete transactions
  Transaction* incomplete = EEPROM_findTransactionByState(STATE_DISPENSING);
  
  if (incomplete != NULL) {
    // ESP8266 crashed mid-dispense
    // DON'T continue dispensing (motor might be jammed)
    // Mark as ERROR so terminal can query final count
    incomplete->state = STATE_ERROR;
    EEPROM_updateTransaction(incomplete);
    EEPROM.commit();
    
    Serial.printf("Recovered incomplete transaction: %s (dispensed %d of %d)\n",
                  incomplete->txId, incomplete->dispensed, incomplete->quantity);
  }
}
```

### API Behavior After ESP8266 Crash

**GET /dispense/:txId** must return recovered state:

```json
{
  "tx_id": "abc123",
  "state": "error",
  "quantity": 3,
  "dispensed": 1
}
```

**NOT:**
```json
{
  "error": "Transaction not found"
}
```

### Terminal Crash Recovery Behavior

With ESP8266 state persistence:
1. Terminal reboots, finds incomplete operation in `dispenser_operations` table
2. Queries ESP8266: `GET /dispense/abc123`
3. ESP8266 returns: `{dispensed: 1, state: "error"}`
4. **Terminal creates 1 transaction** → User charged correctly!
5. Terminal cleans up tracking record

Without ESP8266 state persistence:
1. Terminal queries ESP8266 → 404 Not Found
2. **Terminal preserves tracking record** (does NOT clean up)
3. **Manual reconciliation required** → Staff checks dispenser logs
4. Staff creates transaction manually if needed

### Testing Checklist

- [ ] ESP8266 persists transaction to EEPROM before dispensing starts
- [ ] ESP8266 updates EEPROM after each token dispensed
- [ ] ESP8266 updates EEPROM on completion/error
- [ ] Power cycle ESP8266 mid-dispense → transaction recovered on boot
- [ ] GET /dispense/:txId returns recovered transaction state after reboot
- [ ] Terminal recovery service creates correct number of transactions after ESP8266 crash

### Manual Reconciliation Procedure

If ESP8266 doesn't implement state persistence or loses state:

1. **Identify tracking records** that couldn't be recovered (404 from ESP8266)
2. **Check ESP8266 logs** (if available) for transaction history
3. **Check dispenser token count** (if dispenser tracks physical count)
4. **Ask member** if they received tokens (honor system for small amounts)
5. **Create manual transaction** in terminal database if tokens were dispensed
6. **Delete tracking record** after manual reconciliation

### Recommendation

**Implement ESP8266 state persistence immediately.** Without it:
- Users may receive tokens without being charged (revenue loss)
- Manual reconciliation creates staff overhead
- Trust in system is reduced

The EEPROM implementation is ~50 lines of code but critical for reliability.
