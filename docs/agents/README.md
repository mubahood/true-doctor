# Agent handbook

Written for the next person (or agent) who works on True-Doctor and has not been here before. It explains **the approach we have taken and why**, so that new work looks like it belongs.

Start with [`../../AGENTS.md`](../../AGENTS.md) (one page), then read the chapter for the area you are about to change.

| # | Chapter | In one line |
|---|---|---|
| 01 | [Approach](01-approach.md) | The principles, and the trade-offs behind them |
| 02 | [Backend](02-backend.md) | Services, tenancy, money, permissions, the API, idempotency, PDFs |
| 03 | [UI and density](03-ui-and-density.md) | The design system, the compact rule, web and phone kits |
| 04 | [Offline and the app](04-offline-and-app.md) | The sync protocol, device copies, what the phone may do offline |
| 05 | [Editions and releases](05-editions-and-releases.md) | SaaS vs standalone, licences, releasing, hotfixing a client |
| 06 | [Testing and review](06-testing-and-review.md) | What "done" means here |
| 07 | [Playbooks](07-playbooks.md) | Recipes: new screen, new endpoint, new phone screen, release, hotfix |
| 08 | [Lessons](08-lessons.md) | Real incidents and the rule each one produced |

Existing per-module docs stay where they are (see [`../README.md`](../README.md)); this handbook links to them instead of repeating them. When this handbook and the code disagree, the code is right: fix the handbook.

The app has its own guide at `true-doctor-mobo/AGENTS.md` and `true-doctor-mobo/docs/AGENT_GUIDE.md`.
