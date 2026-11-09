<?php
// CI only (E2E workflow): replaces demo/additional.php in the container. The demo's
// reverse-proxy settings make TYPO3 treat every request as HTTPS (Railway terminates TLS),
// which breaks the backend on plain http://127.0.0.1 in CI. Everything else as on Railway.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';
$GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'lax';
