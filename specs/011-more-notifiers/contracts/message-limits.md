# Contract: rendering, limits and rate limits per channel

Input to a notifier is a `Message`. If `sections` is non-empty it is rendered from the sections; otherwise from `text`
(a plain string, e.g. the `check` test message). `Message::text` is never sent when sections exist (it has the
defang characters removed).

## Source dialect (what sections contain)

`*bold*`, `` `code` ``, `<https://url|label>`, entities `&amp; &lt; &gt;`, other characters literal. Section text
from model/log strings is already `SlackText::escape()`d: `&<>` entity-encoded, backtick -> `'`, `@` + ZWSP, `://` ->
`:` ZWSP `//`, `www` ZWSP `.`, `host` `.` ZWSP `tld` (file names such as `X.php` stay intact).

**Order of operations (security invariant)**: (1) tokenise the source for `<https?://...|label>`, `*...*`, `` `...` ``;
(2) for text outside tokens: decode the three entities, then escape for the target; (3) inside a token, escape the
label/body the same way; the URL must match `^https?://[^\s|<>"]+$` or the whole token is treated as text.
ZWSP is preserved everywhere.

## Telegram (`TelegramText`)

| Source | Output |
|---|---|
| text outside tokens | decode entities, then `&`->`&amp;` `<`->`&lt;` `>`->`&gt;` |
| `*x*` | `<b>x</b>` |
| `` `x` `` | `<code>x</code>` |
| `<url\|label>` | `<a href="url">label</a>` (`&` `"` in url escaped) |
| kind `header` | whole text in `<b>` (no nested bold) |
| `parse_mode` | `HTML`; `link_preview_options.is_disabled` true |

Allowed tags in any output: `b`, `i`, `code`, `a`. A test asserts no other `<` appears unescaped.
Limit: 4096 characters after parsing; budget **4000** on the rendered string.

## Discord (`DiscordText`)

| Source | Output |
|---|---|
| text outside tokens | decode entities, then backslash-escape `\ * _ ~ \| ` + "`" + ` [ ] ( )`; `<` -> `<` + ZWSP; at line start also `# - > +` and `1.` forms (prefix backslash) |
| `*x*` | `**x**` |
| `` `x` `` | `` `x` `` (a backtick inside is replaced by `'`) |
| `<url\|label>` | `[label](<url>)` (angle brackets suppress the embed) |
| kind `header` | `**text**` |
| body | `{"content": ..., "allowed_mentions": {"parse": []}, "flags": 4}` |

Limit: 2000 characters per `content`; budget **1900**.
`allowed_mentions.parse = []` is sent on every request, independently of escaping (defence in depth).

## Splitting (`MessageSplitter`)

Input: rendered sections (strings), limit, number prefix `(n/m) ` (reserve 8 characters for it).

1. Greedy pack: add sections in order, joined by `"\n\n"`, to the current part while it fits; otherwise start a new part.
   A section is never split across parts. Header only in part 1, footer only in the last part (they are just sections).
2. A single section longer than `limit - reserve`: cut the **source** text, re-render, shrink until
   `rendered + marker` fits; marker is the visible text `… (cut)` rendered through the formatter. The cut avoids
   splitting a surrogate pair/ZWSP-joined unit (cut on a code point boundary).
3. More than 10 parts: parts 1-9 as packed; part 10 is the remainder replaced by `… N more sections not shown`
   (N = sections not placed).
4. One part: no prefix. Two or more: each starts `(n/m) ` (`(1/3) `).
5. Counting is in characters (multi-byte aware), on the final string.

Examples (limit 4000, sections of 1500/1500/1500): 2 parts `(1/2)`: sections 1+2; `(2/2)`: section 3.
Section of 6000 chars: one part, cut near 3900 with `… (cut)`.

## Rate limits and failure

| | Telegram | Discord |
|---|---|---|
| 429 hint | JSON `parameters.retry_after` (int s) | JSON `retry_after` (float s, rounded up) else header `Retry-After` |
| retried statuses | 429 only | 429 only |
| attempts per part | 3 | 3 |
| single wait cap | 30 s | 30 s |
| budget per message (all parts) | `min(60, max(1, lock_ttl/10))` s | same |
| success | any 2xx | any 2xx (204 expected) |
| other | `DeliveryFailed("telegram delivery failed: HTTP <code>")` | `DeliveryFailed("discord delivery failed: HTTP <code>")` |
| transport error | `... failed: transport error` (no previous) | same |
| split message | suffix ` at part n of m` | same |

A hint above the remaining budget fails immediately with `HTTP 429` (no sleep). The exceptions are never chained.
No pacing between parts beyond 429 handling.

## Request safety

- Telegram: token shape checked before the URL is built; URL `https://api.telegram.org/bot<token>/sendMessage`; no
  redirects followed (`allow_redirects` false).
- Discord: URL must satisfy the webhook pattern (host `discord.com` / `discordapp.com`); no redirects.
- Both: JSON body, `http_errors` false, timeouts from the shared HTTP client options.
