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

document.addEventListener('DOMContentLoaded', function () {
    var testBtn = document.getElementById('testBtn');
    if (testBtn) {
        testBtn.addEventListener('click', testConnection);
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
});
