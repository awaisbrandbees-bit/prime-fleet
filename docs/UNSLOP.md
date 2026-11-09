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

## Current blockers

1. **Unslop App** needs:
   - GitHub account with admin on `awaisbrandbees-bit/prime-fleet`
   - Unslop team / beta access ([unslop.codes](https://unslop.codes/en) — signup may be closed)
   - App install limited to **this** Prime Fleet repo only

## Integration steps (when ready)

### 1. Repo (done when code is pushed)

Dedicated repo: [awaisbrandbees-bit/prime-fleet](https://github.com/awaisbrandbees-bit/prime-fleet).

### 2. Get Unslop access

1. Open [https://unslop.codes/en](https://unslop.codes/en) when sign-up / invite is available.
2. Create / join the **team** account.
3. Connect billing/quota if required.

### 3. Install GitHub App — Prime Fleet only

1. From Unslop dashboard → connect GitHub → install the Unslop / Anti-Slop Gatekeeper App.
2. Choose account/org → **select only the Prime Fleet repository**.
3. Confirm permissions (typically: PR read/write for checks + comments, contents read for diffs).

### 4. Branch protection (optional but recommended)

On Prime Fleet `main` (or release branch):

1. Settings → Branches → Branch protection.
2. Require status checks → enable **Anti-Slop Gatekeeper**.
3. Do **not** apply this to Sletci repos.

### 5. Team rules (optional)

In Unslop dashboard, add plain-language team rules for Prime Fleet, e.g.:

- Do not invent WordPress APIs; use existing `Prime_Fleet\` patterns.
- No hardcoded secrets; use `wp-config` / constants for tokens.
- Prefer CSS variables / `skin.css` tokens; no one-off hex in new UI.

Findings from team rules are labeled as yours on the PR.

### 6. Verify with a test PR

1. Open a small PR on **Prime Fleet** only.
2. Confirm check **Anti-Slop Gatekeeper** appears.
3. Confirm **no** Unslop checks on any Sletci PR.

## Deep review

Unslop “deep review” is **on demand** (check UI / dashboard), slower, quota-limited. Use for large AI PRs; keep everyday gate on the fast path.

## Owner checklist

- [ ] Prime Fleet GitHub repo created / remote added  
- [ ] Unslop beta / team access obtained  
- [ ] GitHub App installed on **Prime Fleet repo only**  
- [ ] Branch protection requires Anti-Slop Gatekeeper (optional)  
- [ ] Confirmed Sletci repos have **zero** Unslop installation  
- [ ] First test PR reviewed successfully  

## Related

- Product plan: `../GENERIC_PRODUCT_REBRAND_PLAN.md` (Phase 3 + Unslop workable)
