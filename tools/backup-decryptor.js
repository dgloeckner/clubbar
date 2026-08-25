/*
 * Opening a Club Bar backup archive, offline.
 *
 * This file is the crypto half of tools/backup-decryptor.html, kept separate
 * for one reason: the interop test loads *this exact file* in node and opens an
 * archive that PHP sealed. A reimplementation living inside the HTML could
 * drift from BackupSealedBox.php, and nothing would notice until a restore.
 *
 * Format (BackupSealedBox.php is the authority):
 *   magic    "CLUBBAR-BACKUP" + one version byte
 *   header   4-byte big-endian length, then JSON
 *   stream   crypto_secretstream header
 *   keys     per recipient: 4-byte length, then crypto_box_seal(stream key)
 *   body     per chunk: 4-byte length, then a secretstream chunk
 *
 * Version 2's header describes the archive as well as naming its recipients:
 * instance, schema version, dump format, table manifest, and the plaintext's
 * length and SHA-256. There is no backup state in the application's database,
 * so this header is the record (ADR-0049 decision 8) - and all of it is
 * readable here, with no key.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.BackupDecryptor = factory();
  }
}(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var MAGIC = 'CLUBBAR-BACKUP';
  var VERSION = 2;

  function bytesToAscii(bytes) {
    var s = '';
    for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return s;
  }

  function take(bytes, cursor, length) {
    if (length < 0 || cursor.at + length > bytes.length) {
      throw new Error('Archive ends unexpectedly - it is truncated or not a Club Bar backup.');
    }
    var slice = bytes.subarray(cursor.at, cursor.at + length);
    cursor.at += length;
    return slice;
  }

  function readLength(bytes, cursor) {
    var raw = take(bytes, cursor, 4);
    return ((raw[0] << 24) >>> 0) + (raw[1] << 16) + (raw[2] << 8) + raw[3];
  }

  /**
   * The header, readable with no key at all - so the tool can say which key to
   * fetch, instead of failing with a decryption error and leaving the holder to
   * guess which envelope in the safe was meant.
   */
  function readHeader(archive) {
    if (bytesToAscii(archive.subarray(0, MAGIC.length)) !== MAGIC) {
      throw new Error('Not a Club Bar backup archive (bad magic).');
    }

    var cursor = { at: MAGIC.length };
    var containerVersion = take(archive, cursor, 1)[0];

    if (containerVersion !== VERSION) {
      throw new Error(
        'Unsupported archive version ' + containerVersion + '; this tool reads version ' + VERSION + '.'
      );
    }

    var length = readLength(archive, cursor);
    var header = JSON.parse(bytesToAscii(take(archive, cursor, length)));

    if (header.version !== VERSION) {
      throw new Error(
        'Archive header says version ' + header.version + ' but the container says ' + VERSION + '.'
      );
    }

    return { header: header, bodyStart: cursor.at };
  }

  function describeRecipients(recipients) {
    return recipients.map(function (r) {
      return '"' + r.label + '" (' + r.fingerprint.slice(0, 12) + ')';
    }).join(', ');
  }

  /**
   * @param sodium    an initialised libsodium-wrappers
   * @param archive   Uint8Array
   * @param secretKey Uint8Array, 32 bytes
   * @returns Uint8Array plaintext
   */
  function open(sodium, archive, secretKey) {
    var parsed = readHeader(archive);
    var header = parsed.header;
    var cursor = { at: parsed.bodyStart };

    var streamHeader = take(archive, cursor, sodium.crypto_secretstream_xchacha20poly1305_HEADERBYTES);
    var publicKey = sodium.crypto_scalarmult_base(secretKey);
    var streamKey = null;

    for (var i = 0; i < header.recipients.length; i++) {
      var sealed = take(archive, cursor, readLength(archive, cursor));
      if (streamKey !== null) continue;
      try {
        streamKey = sodium.crypto_box_seal_open(sealed, publicKey, secretKey);
      } catch (e) {
        // Wrong recipient for this envelope. Ordinary: we try each in turn.
      }
    }

    if (streamKey === null) {
      throw new Error(
        'This archive was not sealed to this key. It names '
        + describeRecipients(header.recipients) + ' - fetch the matching private half.'
      );
    }

    var state = sodium.crypto_secretstream_xchacha20poly1305_init_pull(streamHeader, streamKey);
    var parts = [];
    var total = 0;
    var sawFinal = false;

    while (cursor.at < archive.length) {
      var chunk = take(archive, cursor, readLength(archive, cursor));
      var result = sodium.crypto_secretstream_xchacha20poly1305_pull(state, chunk);
      if (!result) {
        throw new Error('Archive failed authentication - it is corrupt or has been altered.');
      }

      parts.push(result.message);
      total += result.message.length;

      if (result.tag === sodium.crypto_secretstream_xchacha20poly1305_TAG_FINAL) {
        sawFinal = true;
        break;
      }
    }

    if (!sawFinal) {
      throw new Error(
        'Archive ends before its final chunk - it is truncated, and restoring it would '
        + 'silently lose whatever came after the cut.'
      );
    }

    var out = new Uint8Array(total);
    var at = 0;
    for (var j = 0; j < parts.length; j++) {
      out.set(parts[j], at);
      at += parts[j].length;
    }

    return out;
  }

  /**
   * The `config.php` a dump carries, or null when it carries none.
   *
   * The counterpart of PHP's `ConfigSnapshot::extract()`, and the two have to
   * agree about one format: a block of SQL comments, appended after the dump's
   * footer, holding base64. Comments because the payload must stay a single
   * importable `.sql` - the reference host restores by pasting one file into
   * phpMyAdmin (ADR-0031). Base64 because `config.php` is PHP and PHP can
   * contain anything, including a line that looks like the close marker.
   *
   * Only the tail is scanned. The block is appended, a club database is
   * single-digit megabytes and this runs in a browser on somebody's laptop;
   * turning the whole plaintext into a JS string to find something that is
   * always in the last few kilobytes is work with no payoff.
   */
  function extractConfig(plaintext) {
    var OPEN = '-- >>> CONFIG config.php (base64)';
    var CLOSE = '-- <<< CONFIG';

    // Generous enough for any real config.php plus its preamble, small enough
    // that the string conversion is free.
    var WINDOW = 1024 * 1024;

    var from = Math.max(0, plaintext.length - WINDOW);
    var tail = '';
    // Chunked: String.fromCharCode.apply with a megabyte of arguments blows the
    // call stack in every browser this tool has to work in.
    for (var i = from; i < plaintext.length; i += 8192) {
      tail += String.fromCharCode.apply(
        null,
        plaintext.subarray(i, Math.min(i + 8192, plaintext.length))
      );
    }

    // From the end, for the reason PHP's ConfigSnapshot::extract() gives: the
    // block is appended last, so the final occurrence is the real one and a row
    // whose data contained the marker text cannot shadow it.
    var start = tail.lastIndexOf(OPEN + '\n');
    if (start === -1) {
      return null;
    }
    start += OPEN.length + 1;

    var end = tail.indexOf(CLOSE, start);
    if (end === -1) {
      return null;
    }

    var base64 = '';
    var lines = tail.slice(start, end).split('\n');
    for (var j = 0; j < lines.length; j++) {
      if (lines[j].indexOf('-- ') !== 0) {
        continue;
      }

      var payload = lines[j].slice(3);

      // The human-readable preamble lines are comments too. Base64's alphabet
      // has no space, so anything carrying one is prose rather than payload.
      if (payload === '' || !/^[A-Za-z0-9+/=]+$/.test(payload)) {
        continue;
      }

      base64 += payload;
    }

    if (base64 === '') {
      return null;
    }

    var binary;
    try {
      binary = atob(base64);
    } catch (e) {
      return null;
    }

    var out = new Uint8Array(binary.length);
    for (var k = 0; k < binary.length; k++) {
      out[k] = binary.charCodeAt(k);
    }

    return out;
  }

  return {
    MAGIC: MAGIC,
    VERSION: VERSION,
    readHeader: readHeader,
    open: open,
    extractConfig: extractConfig
  };
}));
