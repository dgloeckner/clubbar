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
 *
 * Version 3 gzips the body before sealing it (#691), and the header says so in
 * `compression`. open() is async from that version on, because inflating uses
 * DecompressionStream - which is native in every browser this tool targets and
 * needs no second vendored library.
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
  var VERSION = 3;

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
   * @returns Promise<Uint8Array> the plaintext, inflated if the header says so
   */
  function open(sodium, archive, secretKey) {
    // Always a promise, never a synchronous throw. Every refusal below - a
    // wrong key, a truncated file, a tampered chunk - happens before the
    // inflate, so without this wrapper they would escape past a caller's
    // .then(ok, fail) and be swallowed as an unhandled rejection. The holder
    // would see nothing at all, which is the same failure as an archive that
    // decrypts and shows nothing: a tool that has an answer and does not
    // give it.
    try {
      return openOrThrow(sodium, archive, secretKey);
    } catch (e) {
      return Promise.reject(e);
    }
  }

  function openOrThrow(sodium, archive, secretKey) {
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

    return decompress(out, header.compression);
  }

  /**
   * Turn the decrypted body back into SQL, driven by what the header says
   * rather than by sniffing the bytes.
   *
   * An unknown codec is refused rather than guessed at: handing a holder a
   * still-compressed file named `.sql` would give them something that imports
   * as garbage, which is the failure this container exists to prevent - and
   * they would meet it at the worst moment of the club's year.
   *
   * @returns Promise<Uint8Array>
   */
  function decompress(body, compression) {
    if (compression === 'none' || compression === undefined) {
      return Promise.resolve(body);
    }

    if (compression !== 'gzip') {
      return Promise.reject(new Error(
        'This archive says its body is compressed with "' + compression + '", which this tool '
        + 'cannot decompress. It was written by a newer version of Club Bar - use the '
        + 'decryptor that shipped with it.'
      ));
    }

    if (typeof DecompressionStream !== 'function') {
      return Promise.reject(new Error(
        'This archive is gzip-compressed and this browser has no DecompressionStream. '
        + 'Open it in a current Firefox, Chrome, Edge or Safari.'
      ));
    }

    var stream = new Blob([body]).stream().pipeThrough(new DecompressionStream('gzip'));

    return new Response(stream).arrayBuffer().then(function (buffer) {
      return new Uint8Array(buffer);
    });
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


  /*
   * ---------------------------------------------------------------------
   * Splitting a dump into per-table pieces
   *
   * The reference host restores through phpMyAdmin (ADR-0031), which has an
   * upload limit a club-sized database eventually exceeds. `DatabaseDump`
   * brackets every table with terminated markers precisely so the archive
   * stays addressable when that happens - and until now the runbook asked the
   * *operator* to cut the file at those markers and paste the header lines in
   * front of each piece, by hand, at the worst moment of the club's year.
   *
   * That instruction had a silent failure mode, which is why this exists: a
   * piece imported without the preamble runs in whatever zone the panel
   * happens to be in, and every `TIMESTAMP` in it shifts by that offset,
   * consistently, with nothing about the result looking wrong.
   *
   * `Tests\Support\SqlScript` is the same split on the PHP side, and
   * `RestoreRoundTripTest` proves the pieces it cuts restore into a real
   * database. These two must agree byte for byte: `golden.split.sql` and the
   * expected pieces beside it are produced by PHP, and the interop test asserts
   * this implementation reproduces them.
   *
   * Everything here works on bytes, never on a decoded string. A dump holds
   * member names in utf8mb4 and hex literals standing in for sealed boxes;
   * decoding it to slice it and re-encoding to save it is a round trip with no
   * upside and a real chance of mangling exactly the data a restore exists to
   * recover.
   * ---------------------------------------------------------------------
   */

  var TABLE_OPEN = '-- >>> TABLE ';
  var TABLE_CLOSE = '-- <<< TABLE ';
  var NEWLINE = 10;

  function asciiBytes(text) {
    var out = new Uint8Array(text.length);
    for (var i = 0; i < text.length; i++) out[i] = text.charCodeAt(i) & 0xff;
    return out;
  }

  function decodeUtf8(bytes) {
    return new TextDecoder('utf-8').decode(bytes);
  }

  /** Uint8Array has no indexOf for a subsequence, and a dump is too big to stringify. */
  function findBytes(haystack, needle, from) {
    if (needle.length === 0) return from;

    var last = haystack.length - needle.length;
    for (var i = from; i <= last; i++) {
      if (haystack[i] !== needle[0]) continue;

      var j = 1;
      while (j < needle.length && haystack[i + j] === needle[j]) j++;
      if (j === needle.length) return i;
    }

    return -1;
  }

  function concatBytes(a, b) {
    var out = new Uint8Array(a.length + b.length);
    out.set(a, 0);
    out.set(b, a.length);
    return out;
  }

  /**
   * The dump's session settings, without its data.
   *
   * A section imported on its own still needs them. `SQL_MODE` is what keeps
   * backslash escapes meaning what the emitter meant, and `time_zone` is the
   * one whose absence has no symptom.
   *
   * The `ALTER DATABASE` line is dropped, for the reason PHP's
   * `sessionPreamble()` gives: it names no database, so it would retarget
   * whichever schema the operator happens to have open.
   *
   * @returns string
   */
  function sessionPreamble(plaintext) {
    var first = findBytes(plaintext, asciiBytes('\n' + TABLE_OPEN), 0);

    if (first === -1) {
      throw new Error('This does not look like a Club Bar dump: it has no table markers.');
    }

    return decodeUtf8(plaintext.subarray(0, first)).replace(/^ALTER DATABASE .*\n?/gm, '');
  }

  /**
   * Every table's section, each already carrying the preamble it needs.
   *
   * Sections are returned in the order the dumper wrote them, which is name
   * order rather than dependency order - importable in any order because the
   * preamble switches foreign-key checks off.
   *
   * @returns {{preamble: string, tables: Array<{name: string, rows: ?number,
   *            bytes: number, sql: Uint8Array}>}}
   */
  function splitByTable(plaintext, manifest) {
    var preamble = sessionPreamble(plaintext);
    var preambleBytes = new TextEncoder().encode(preamble);
    var openMarker = asciiBytes('\n' + TABLE_OPEN);

    var tables = [];
    var at = 0;

    for (;;) {
      var found = findBytes(plaintext, openMarker, at);
      if (found === -1) break;

      var start = found + 1; // past the newline, at the marker itself
      var nameEnd = plaintext.indexOf(NEWLINE, start);
      if (nameEnd === -1) {
        throw new Error('This dump is truncated: a table marker has no end of line.');
      }

      var name = decodeUtf8(plaintext.subarray(start + TABLE_OPEN.length, nameEnd));
      var close = asciiBytes(TABLE_CLOSE + name + '\n');
      var end = findBytes(plaintext, close, nameEnd);

      // The archive is authenticated, so a missing close marker cannot be
      // corruption in transit - it means this reader and the dumper disagree
      // about the format. Refuse the table rather than hand over a piece that
      // silently stops somewhere in the middle of its rows.
      if (end === -1) {
        throw new Error(
          'The section for `' + name + '` has no closing marker; this dump was written in a '
          + 'format this tool does not know. Import the whole .sql instead.'
        );
      }

      var section = plaintext.subarray(start, end + close.length);

      tables.push({
        name: name,
        rows: manifest && Object.prototype.hasOwnProperty.call(manifest, name)
          ? manifest[name]
          : null,
        bytes: preambleBytes.length + section.length,
        sql: concatBytes(preambleBytes, section)
      });

      at = end + close.length;
    }

    return { preamble: preamble, tables: tables };
  }

  return {
    MAGIC: MAGIC,
    VERSION: VERSION,
    readHeader: readHeader,
    open: open,
    extractConfig: extractConfig,
    sessionPreamble: sessionPreamble,
    splitByTable: splitByTable
  };
}));
