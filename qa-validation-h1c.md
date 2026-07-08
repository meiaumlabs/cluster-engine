# QA Validation — h-1c Fix Round
Reviewer: pane-68 · Mission YJqk4owjsLgV · 2026-07-08

## BUG-1 — class-ce-images.php OpenAI HTTP guard

**Required:** `if ($http_code && $http_code >= 400)` (no `&& $err`), fallback `$api_msg = $err ? $err : wp_remote_retrieve_response_message($res)`.

**Actual (lines 279-282):**
```php
if ( $http_code && $http_code >= 400 ) {
    $api_msg = $err ? $err : wp_remote_retrieve_response_message( $res );
    return new WP_Error( 'ce61_img', sprintf( 'OpenAI API HTTP %d: %s', $http_code, $api_msg ) );
}
```
Matches spec exactly. Consistency check:
- Imagen  line 187: `if ( $http_code && $http_code >= 400 )` ✓
- Gemini  line 216: `if ( $http_code && $http_code >= 400 )` ✓
- OpenAI  line 279: `if ( $http_code && $http_code >= 400 )` ✓

**VERDICT: FIXED ✓**

---

## BUG-2 — app.js image-generate stale-request guards

**Required .then guard (≈1294):** `if (_activeController !== ctrl || !$('#ce-img-out')) return;`
**Actual line 1294:** `if (_activeController !== ctrl || !$('#ce-img-out')) { return; }` ✓

**Required .catch guard (≈1308):** `if (_activeController !== ctrl) return;`
**Actual line 1308:** `if (_activeController !== ctrl) { return; }` ✓

Both stale-request guards present and correct. Stale requests cannot write to DOM or button state.

**VERDICT: FIXED ✓**

---

## BUG-3 — api() external signal + internal timeout simultaneously

**Call site (line 1293):** `{ signal: ctrl.signal, timeout: 90000 }` ✓

**api() implementation (lines 34-57):**
| Requirement | Code | Line |
|---|---|---|
| Internal AbortController always created | `var controller = new AbortController();` | 36 |
| External abort forwarded (aborted check) | `if (opts.signal.aborted) { controller.abort(); }` | 39 |
| External abort forwarded (listener) | `opts.signal.addEventListener('abort', function () { controller.abort(); });` | 40 |
| Timer armed when timeoutMs > 0 | `var timer = timeoutMs ? setTimeout(...) : null;` | 42 |
| Timer cleared on .then (first handler) | `if (timer) { clearTimeout(timer); }` | 44 |
| Timer cleared on .catch | `if (timer) { clearTimeout(timer); }` | 52 |

No timer or signal leak. External abort listener is bound to the external controller's signal (short-lived object, GC'd with the controller). No dangling reference.

**VERDICT: FIXED ✓**

---

## Regression check — api() callers passing no opts

Default timeout path: `var timeoutMs = opts.timeout != null ? opts.timeout : 70000;` (line 35)
→ All existing callers passing no opts receive 70 s timeout unchanged. No new code paths introduced for the zero-opts case.

**VERDICT: NO REGRESSION ✓**

---

## Syntax checks

| File | Command | Result |
|---|---|---|
| admin/js/app.js | `node --check` | **EXIT 0** |
| includes/class-ce-images.php | `php -l` | php not in PATH — manual scan: valid `<?php` open, all braces/brackets balanced, no heredoc or string issues observed |

**VERDICT: PASS ✓**

---

## Overall: ALL 3 BUGS FIXED — PASS
