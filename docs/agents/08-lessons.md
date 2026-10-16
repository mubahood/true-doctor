# 08 · Lessons already paid for

Each row is a real incident and the rule it produced. Skim before risky work.

| Incident | Rule |
|---|---|
| Middleware ran route-model binding before the tenant was resolved: one hospital could open another's patient by uuid | Tenant resolution runs before binding (`priority()`); every new tenant model gets an isolation test |
| `unique`/`exists` validation ignored the tenant scope, so it both collided and leaked existence across hospitals | Write the hospital filter into those rules |
| Divine Dental's invoice PDF 500'd: DomPDF's `public_path` didn't exist under the split cPanel layout; fonts embedded in full (1.9 MB) | `pointPdfAtAFolderThatExists()`; subset fonts; every printable in `GeneratedDocumentTest` including a split-layout and size test |
| A hotfix added a new class file; the client's classmap-authoritative autoload didn't know it → whole site down ~1 min | Hotfix in files/classes that already exist; back up and rehearse the rollback; verify straight after |
| Release 1.0.3 was published from an old staged build | Check the commit in `PACKAGE.json` before publishing |
| Shared host defaulted to MyISAM; install died at migration 16 | `engine => InnoDB` in config; installer checks the host, doesn't assume |
| API insights returned an object, the app expected a list; tests with a hand-made fake passed | Compare real server JSON with the parser; keep fixtures real |
| Local DB missing two migrations gave a fake 500 on `schedules` | `migrate` locally after pulling before debugging |
| Retrying a payment after a lost reply could double-charge | `Idempotency-Key` end to end; client reuses the key after no-answer failures |
| PIN lock rendered under open screens | Overlay above the Navigator |
| Editing card terms wiped the max credit | Partial updates merge; never overwrite with defaults |
| "Keep mine" in a sync conflict wiped a lab flag; backoff never re-armed; attempts burned while offline; newer pull dropped manual conflicts | The sync engine has explicit tests for each; touch it only with an independent review |
| A form closed mid-POST lost the result; re-save after a refusal registered a ghost patient | Forms own their callbacks; a refusal edits the *same* record |
| Storage trim could hang on `VACUUM` and wasn't atomic | Trim atomically; VACUUM in try/catch; never trim unsent work |
| Full PHPUnit run exhausted 512 MB | Run per folder |
| `dart format` on whole folders touched unrelated files | Format only what you changed; `git diff --stat` before committing |
| macOS `sed -i` without `''` and heredoc quote escapes corrupted edits | Use the editor tools or python for edits; re-read the result |
| `git add -A` while sub-agents were editing swept up their half-work | Add explicit paths |
| A first draft of a client guide would have overcharged scaling and booked existing stock as supplier debt | Check every instruction in a client document against the code |
| Mid-session UI at generous padding wasted screen room for the owner | The compact rule ([03](03-ui-and-density.md)) |

## Known gaps (as of 2026-10-16)

- Web commits (idempotency, API for the whole back office, printables fix, patient record, compact UI) are **local, unpushed, undeployed**; Divine Dental's server has only the PDF hotfix by hand.
- Lab worklist tombstones: fixed (2026-10-17, `docs/api/plane.md` section 6). Radiology list pages with `meta.total` like every list.
- Idempotency has a documented crash-window gap.
- iOS backup exclusion and the QR scanner are untested on real devices; macOS e2e `orders_test` never completed (locked screen).
- Web patient-record tab scrolling, "View all" buttons and the compact density were not checked in a browser.
- FCM push is waiting on a Firebase project; CI is blocked by billing.
