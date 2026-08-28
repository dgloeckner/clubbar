// Installer wizard behaviour, split out of install.php (#250, ADR-0031 layer
// L2): the panel's Content-Security-Policy applies to every response this
// package serves, install.php included, and an inline <script> or onclick=
// attribute is exactly what script-src 'self' (no unsafe-inline) blocks. A
// same-origin file loaded via <script src> is unaffected.

function testConnection() {
    var btn = document.getElementById('testBtn');
    var result = document.getElementById('testResult');
    btn.disabled = true;
    result.textContent = 'Testing...';
    result.className = '';
    var form = document.getElementById('dbForm');
    var params = new URLSearchParams({
        action: 'test_db',
        host: form.db_host.value,
        port: form.db_port.value,
        name: form.db_name.value,
        user: form.db_user.value,
        pass: form.db_pass.value
    });
    fetch('?' + params)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                result.textContent = 'Connection successful!';
                result.className = 'test-ok';
            } else {
                result.textContent = 'Failed: ' + data.error;
                result.className = 'test-fail';
            }
            btn.disabled = false;
        })
        .catch(function() {
            result.textContent = 'Request failed — check your server.';
            result.className = 'test-fail';
            btn.disabled = false;
        });
}

// Step 5 (#405): has a scheduled drain run ever been observed? A read, never a
// trigger — running the drain from here would prove the code works, not that
// anything is scheduled to call it, which is the only thing the gate asks.
function checkScheduler() {
    var btn = document.getElementById('cronCheckBtn');
    var result = document.getElementById('cronCheckResult');
    btn.disabled = true;
    result.textContent = 'Checking...';
    result.className = '';
    fetch('?action=check_cron')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.verified) {
                result.textContent = 'A scheduled run was recorded at ' + data.last_run_at +
                    (data.source ? ' (' + data.source + ')' : '') + '. The scheduler is working.';
                result.className = 'test-ok';
            } else if (data.error) {
                result.textContent = 'Could not check: ' + data.error;
                result.className = 'test-fail';
            } else {
                result.textContent = 'No run recorded yet. If you have just added the cron job, wait for the ' +
                    'next tick and check again — you can also finish now and check from the admin panel.';
                result.className = 'test-fail';
            }
            btn.disabled = false;
        })
        .catch(function() {
            result.textContent = 'Request failed — check your server.';
            result.className = 'test-fail';
            btn.disabled = false;
        });
}

// Step 6 — Backups (#735): repeating label/key recipient rows instead of one
// freeform "label:key per line" textarea. Normalizes a pasted key the way the
// server does (whitespace stripped, lowercased) and computes its fingerprint
// live, the same SHA-256-of-the-raw-key value tools/keypair-generator.html
// and the archive header show — so a swapped, truncated or wrong-encoding
// paste is visible before the operator ever submits, not after a round trip.
var RECIPIENT_HEX = /^[0-9a-f]{64}$/;
// The classic wrong paste: the IBAN keypair's base64 public key, from higher
// up the same generator page, is 44 base64 characters ending in "=".
var RECIPIENT_BASE64_IBAN = /^[A-Za-z0-9+/]{43}=$/;
var RECIPIENT_LABEL = /^[A-Za-z0-9_-]{1,64}$/;

function normalizeRecipientKey(raw) {
    return raw.replace(/\s+/g, '').toLowerCase();
}

function hexToBytes(hex) {
    var bytes = new Uint8Array(hex.length / 2);
    for (var i = 0; i < bytes.length; i++) {
        bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
    }
    return bytes;
}

function bytesToHex(buffer) {
    return Array.from(new Uint8Array(buffer))
        .map(function (b) { return b.toString(16).padStart(2, '0'); })
        .join('');
}

function setRecipientFeedback(row, text, isError) {
    var feedback = row.querySelector('[data-role="feedback"]');
    if (!feedback) return;
    feedback.textContent = text;
    feedback.className = isError ? 'error-inline' : 'recipient-key-feedback muted';
}

function updateRecipientKeyFeedback(input) {
    var row = input.closest('.recipient-row');
    if (!row) return;

    var normalized = normalizeRecipientKey(input.value);
    if (normalized !== input.value) {
        input.value = normalized;
    }
    row.classList.remove('recipient-row-error');

    if (normalized === '') {
        setRecipientFeedback(row, '', false);
        return;
    }

    if (RECIPIENT_BASE64_IBAN.test(normalized)) {
        setRecipientFeedback(
            row,
            'That looks like the base64 IBAN public key from higher up the generator ' +
                'page — this field wants the 64-character hex output under "Backup archive keys" instead.',
            true
        );
        return;
    }

    if (!RECIPIENT_HEX.test(normalized)) {
        setRecipientFeedback(row, 'Expected 64 hex characters (0-9, a-f) — got ' + normalized.length + '.', true);
        return;
    }

    if (!(window.crypto && window.crypto.subtle)) {
        setRecipientFeedback(row, '', false);
        return;
    }

    setRecipientFeedback(row, 'Checking fingerprint…', false);
    crypto.subtle.digest('SHA-256', hexToBytes(normalized)).then(function (digest) {
        if (normalizeRecipientKey(input.value) !== normalized) return; // superseded by a later edit
        setRecipientFeedback(row, 'Fingerprint: ' + bytesToHex(digest), false);
    }).catch(function () {
        setRecipientFeedback(row, '', false);
    });
}

function updateRecipientLabelValidity(input) {
    var value = input.value.trim();
    input.setCustomValidity(
        value !== '' && !RECIPIENT_LABEL.test(value)
            ? 'Letters, digits, hyphens and underscores only, max 64 characters.'
            : ''
    );
}

function updateRecipientRemoveVisibility(container) {
    var rows = container.querySelectorAll('.recipient-row');
    var hideRemove = rows.length <= 1;
    Array.prototype.forEach.call(rows, function (row) {
        var btn = row.querySelector('.recipient-remove');
        if (btn) btn.hidden = hideRemove;
    });
}

function wireRecipientRow(row) {
    var keyInput = row.querySelector('.recipient-key-input');
    if (keyInput) {
        keyInput.addEventListener('input', function () { updateRecipientKeyFeedback(keyInput); });
    }
    var labelInput = row.querySelector('input[name="recipient_label[]"]');
    if (labelInput) {
        labelInput.addEventListener('input', function () { updateRecipientLabelValidity(labelInput); });
    }
    var removeBtn = row.querySelector('.recipient-remove');
    if (removeBtn) {
        removeBtn.addEventListener('click', function () {
            var container = document.getElementById('recipient-rows');
            row.remove();
            updateRecipientRemoveVisibility(container);
        });
    }
}

var recipientRowCounter = 0;

function addRecipientRow() {
    var container = document.getElementById('recipient-rows');
    if (!container) return;

    recipientRowCounter++;
    var suffix = 'new' + recipientRowCounter;
    var row = document.createElement('div');
    row.className = 'recipient-row';
    row.innerHTML =
        '<div class="recipient-row-fields">' +
        '<div class="recipient-field recipient-field-label">' +
        '<label for="recipient_label_' + suffix + '">Label</label>' +
        '<input type="text" id="recipient_label_' + suffix + '" name="recipient_label[]" ' +
        'placeholder="admin" maxlength="64" autocomplete="off">' +
        '</div>' +
        '<div class="recipient-field recipient-field-key">' +
        '<label for="recipient_key_' + suffix + '">Public key (hex)</label>' +
        '<input type="text" id="recipient_key_' + suffix + '" name="recipient_key[]" ' +
        'class="recipient-key-input" placeholder="64 hex characters" autocomplete="off" spellcheck="false">' +
        '</div>' +
        '<button type="button" class="recipient-remove" aria-label="Remove this recipient">&times;</button>' +
        '</div>' +
        '<p class="recipient-key-feedback muted" data-role="feedback"></p>';

    container.appendChild(row);
    wireRecipientRow(row);
    updateRecipientRemoveVisibility(container);
}

document.addEventListener('DOMContentLoaded', function () {
    var testBtn = document.getElementById('testBtn');
    if (testBtn) {
        testBtn.addEventListener('click', testConnection);
    }

    var cronCheckBtn = document.getElementById('cronCheckBtn');
    if (cronCheckBtn) {
        cronCheckBtn.addEventListener('click', checkScheduler);
    }

    var migrateBtn = document.getElementById('migrateBtn');
    if (migrateBtn) {
        migrateBtn.addEventListener('click', function (event) {
            // Disabling a submit button inside its own click handler can cancel
            // the browser's default submission before it fires — the explicit
            // submit() call below is not redundant, it is what makes this safe
            // to disable at all.
            event.preventDefault();
            migrateBtn.disabled = true;
            migrateBtn.textContent = 'Running...';
            migrateBtn.form.submit();
        });
    }

    var recipientRows = document.getElementById('recipient-rows');
    if (recipientRows) {
        // Only wired for future edits — a row already showing a fingerprint or
        // an error was rendered server-side and stays as-is until the operator
        // changes it.
        Array.prototype.forEach.call(recipientRows.querySelectorAll('.recipient-row'), wireRecipientRow);
        updateRecipientRemoveVisibility(recipientRows);
    }

    var addRecipientBtn = document.getElementById('add-recipient-row');
    if (addRecipientBtn) {
        addRecipientBtn.addEventListener('click', addRecipientRow);
    }
});
