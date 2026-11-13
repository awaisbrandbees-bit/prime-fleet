# Unslop — Prime Fleet only

**Scope:** Anti-slop for **Prime Fleet** only.  
**Do not** enable on Sletci (`sle-vip-booking`, `sle-vip-booking-m2`, or any SLE VIP client repo).

| Product | Code gate (aislop) | UI skill (heydamir/unslop) |
|---------|--------------------|----------------------------|
| `prime-fleet/` | Yes | Yes |
| `sle-vip-booking*` | No | No |

There are **two** layers:

1. **Code / PR gate** — [aislop](https://github.com/apps/scan-ai-slop-aislop) (+ future [Unslop App](https://unslop.codes/en))
2. **UI craft skill** — [heydamir/unslop](https://github.com/heydamir/unslop) (installed below)

---

## UI skill — heydamir/unslop (installed)

Turns vibecoded, AI-looking UI into a designed interface. Audit → direction → nine design passes. **Not** the same as the PR code gate.

### Install locations (this machine)

| Path | Role |
|------|------|
| `~/.cursor/skills/unslop/` | Cursor user skill (discoverable in Agent) |
| `prime-fleet/.cursor/skills/unslop/` | Project skill + rule |
| `prime-fleet/.agents/skills/unslop/` | Codex / agents fallback |
| `prime-fleet/.cursor/rules/unslop-ui.mdc` | Cursor rule when editing UI assets |

Credit: Damir Sokolovsky ([@heydamir](https://x.com/heydamir)), [CC BY 4.0](https://github.com/heydamir/unslop).

### How to run

In Cursor chat (Prime Fleet workspace / this folder):

```
unslop this booking screen
unslop the admin settings page
/unslop the offer page hero
```

Or: *“polish this UI so it doesn’t look AI-generated.”* Agent should pick up the skill, audit first, propose direction, then wait for your OK before editing (unless you say just do it).

Optional (story video / live snapshot): from a Node project dir, `npm i -D playwright` + `npx playwright install chromium` (+ ffmpeg on PATH for story videos). Prime Fleet already has Playwright MCP for screenshots.

---

## What the Unslop GitHub App is

[Unslop](https://unslop.codes/en) is a **GitHub App** PR review gate for AI-written code (“anti-slop”).

On each PR push it:

1. Runs a **deterministic** rule scan (SQL-in-strings, swallowed errors, fake packages, weak tests, etc.).
2. Runs a **model** review of the diff.
3. Runs a **blind re-check** (second pass without the first model’s reasoning).
4. Posts a check run: **Anti-Slop Gatekeeper** + line comments on the PR.

- **CRITICAL** → check fails (can block merge if required in branch protection).
- **WARNING** → neutral (does not block by itself).
- Runs **beside** CI, not inside your deploy pipeline. Staging/prod only get cleaned code **after** you merge + deploy.

Official site note (as of 2026-11): **Private beta — sign-up is closed.** CLI / editor / MCP not published yet.

## GitHub repo

- **Remote:** https://github.com/awaisbrandbees-bit/prime-fleet.git  
- **Scope:** this repository only — never install Unslop on Sletci / SLE VIP repos.

## Status (2026-11-09)

| Piece | Status |
|-------|--------|
| GitHub repo + code push | Done — [awaisbrandbees-bit/prime-fleet](https://github.com/awaisbrandbees-bit/prime-fleet) |
| **Live PR gate (now)** | **aislop** via GitHub Actions — `.github/workflows/aislop.yml` + `.aislop/config.yml` |
| **UI skill (heydamir/unslop)** | Installed — `.cursor/skills/unslop` + user `~/.cursor/skills/unslop` |
| Official **Unslop** App ([unslop.codes](https://unslop.codes/en)) | Blocked — private beta, sign-up closed. Add when invite arrives. |

aislop catches AI “slop” on **PRs** (code). heydamir/unslop cleans **UI craft** in the editor. Both are Prime Fleet only. Sletci is untouched.

### Optional: aislop GitHub App (extra)

Browser install (select **only** `prime-fleet`):  
https://github.com/apps/scan-ai-slop-aislop/installations/new  

Actions workflow already gates PRs without the App.

## Unslop App (when beta opens)

1. Open [https://unslop.codes/en](https://unslop.codes/en) → team / invite.
2. Install Unslop GitHub App on **`awaisbrandbees-bit/prime-fleet` only** (never Sletci).
3. Branch protection → require **Anti-Slop Gatekeeper** (optional).
4. Optional team rules in Unslop dashboard (WP patterns, no secrets in repo, skin tokens).
5. Open a test PR → confirm check + comments.

## Deep review

Unslop “deep review” is **on demand** (check UI / dashboard), slower, quota-limited. Use for large AI PRs; keep everyday gate on the fast path.

## Owner checklist

- [x] Prime Fleet GitHub repo created / remote added  
- [x] Code pushed to `main`  
- [x] aislop Actions workflow + config committed (live gate)  
- [x] heydamir/unslop UI skill installed (Cursor + project)  
- [ ] Optional aislop GitHub App installed on **Prime Fleet only**  
- [ ] Unslop beta / team access obtained  
- [ ] Unslop GitHub App installed on **Prime Fleet repo only**  
- [ ] Branch protection requires quality gate (optional)  
- [ ] Confirmed Sletci repos have **zero** anti-slop App install  
- [ ] First test PR shows aislop (and later Unslop) check  
- [ ] First UI unslop run on a Prime Fleet screen (audit → direction → passes)  


## Related

- Product plan: `../GENERIC_PRODUCT_REBRAND_PLAN.md` (Phase 3 + Unslop workable)
