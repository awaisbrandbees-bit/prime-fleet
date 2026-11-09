# Unslop — Prime Fleet only

**Scope:** Integrate **Unslop** with the **Prime Fleet** GitHub repository / PRs.  
**Do not** enable Unslop on Sletci (`sle-vip-booking`, `sle-vip-booking-m2`, or any SLE VIP client repo).

| Product | Unslop |
|---------|--------|
| `prime-fleet/` | Yes — target |
| `sle-vip-booking*` | No — out of scope |

## What Unslop is

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
| Official **Unslop** App ([unslop.codes](https://unslop.codes/en)) | Blocked — private beta, sign-up closed. Add when invite arrives. |

aislop is the same class of tool (catch AI “slop” on PRs). It runs **in this repo only**. Sletci is untouched.

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
- [ ] Optional aislop GitHub App installed on **Prime Fleet only**  
- [ ] Unslop beta / team access obtained  
- [ ] Unslop GitHub App installed on **Prime Fleet repo only**  
- [ ] Branch protection requires quality gate (optional)  
- [ ] Confirmed Sletci repos have **zero** anti-slop App install  
- [ ] First test PR shows aislop (and later Unslop) check  


## Related

- Product plan: `../GENERIC_PRODUCT_REBRAND_PLAN.md` (Phase 3 + Unslop workable)
