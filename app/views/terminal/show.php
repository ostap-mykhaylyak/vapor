<?php use Vapor\Core\View; use Vapor\Core\Security; ?>
<div class="term-head">
    <span>⌁ <?= View::e($instance) ?></span>
    <span id="term-status" class="muted">connessione…</span>
</div>
<div id="terminal"></div>

<?php $base = $config['app']['base_url'] ?? ''; ?>
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/xterm/xterm.min.css">
<script src="<?= $base ?>/assets/vendor/xterm/xterm.min.js"></script>
<script src="<?= $base ?>/assets/vendor/xterm/addon-fit.min.js"></script>
<script nonce="<?= View::e(Security::nonce()) ?>">
(function () {
    const WS_URL   = <?= json_encode($wsUrl) ?>;
    const TOKEN    = <?= json_encode($token) ?>;
    const statusEl = document.getElementById('term-status');

    const term = new Terminal({
        cursorBlink: true,
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
        fontSize: 14,
        theme: { background: '#0b0e14', foreground: '#d6deeb' }
    });
    const fit = new FitAddon.FitAddon();
    term.loadAddon(fit);
    term.open(document.getElementById('terminal'));
    fit.fit();

    const url = `${WS_URL}/?token=${encodeURIComponent(TOKEN)}&cols=${term.cols}&rows=${term.rows}`;
    const ws  = new WebSocket(url);
    ws.binaryType = 'arraybuffer';

    ws.onopen = () => {
        statusEl.textContent = 'connesso';
        statusEl.className = 'on';
        term.focus();
    };
    ws.onmessage = (ev) => {
        if (typeof ev.data === 'string') {
            term.write(ev.data);
        } else {
            term.write(new Uint8Array(ev.data));
        }
    };
    ws.onclose = () => {
        statusEl.textContent = 'disconnesso';
        statusEl.className = 'off';
        term.write('\r\n\x1b[90m[connessione chiusa]\x1b[0m\r\n');
    };
    ws.onerror = () => { statusEl.textContent = 'errore'; statusEl.className = 'off'; };

    // Input da tastiera -> server.
    term.onData(d => { if (ws.readyState === 1) ws.send(d); });

    // Resize -> canale di controllo.
    function sendResize() {
        if (ws.readyState === 1) {
            ws.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows }));
        }
    }
    term.onResize(sendResize);
    window.addEventListener('resize', () => { fit.fit(); });
})();
</script>
