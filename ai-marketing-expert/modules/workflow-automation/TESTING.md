# Workflow Automation — Manual Test Guide

Quick pass to verify every feature works. Test as admin on a site with the Content Generator, SEO, Email Marketing, Social, and Chatbot modules active. Run once with Pro active and once with Pro off (gating checks marked **[Free]**).

Open: **AI Marketing Expert → Workflow Automation**.

---

## 1. Navigation & Settings

- [ ] Sidebar shows: Workflows, New Workflow, Upcoming Runs, Settings.
- [ ] Browser back/forward moves between views (hash routing).
- [ ] **Settings** page: toggle "Email me when a workflow run fails" → "Settings saved." toast. Reload → value persisted.

## 2. Templates (New Workflow)

- [ ] Template picker shows "Blank" first, then templates; Pro templates carry a PRO badge.
- [ ] Apply a **free** template (e.g. Weekly Blog Engine) → opens builder as draft, nodes auto-laid-out, connected.
- [ ] **[Free]** Applying a Pro template is blocked (upgrade notice, no workflow created).
- [ ] "Blank" → builder opens with only a Trigger node.

## 3. Builder — Canvas

- [ ] Drag action from palette onto canvas → node appears; click-to-add also works.
- [ ] Connect nodes by dragging handles; each node accepts only ONE inbound edge (tree).
- [ ] Delete key removes selected node/edge; Trigger node cannot be deleted.
- [ ] Unavailable actions (module inactive) greyed out; Pro actions show lock.
- [ ] Save with orphan node or missing required field → validation error, no save.
- [ ] Edit anything → dirty flag; leaving page prompts confirmation.
- [ ] Save, reload builder → node positions, connections, and configs round-trip exactly.

## 4. Builder — Step Options (Config Panel)

Select a **Generate Blog Post** node:
- [ ] **Keywords**: type word + Enter or comma → chip appears with ×; SEO vault keywords appear as suggestions on focus.
- [ ] **Word count**: slider 300–5000 with number input.
- [ ] **Tone override**: dropdown with "Use workflow tone" default; **[Free]** selecting a "(PRO)" tone shows toast "This tone is available in Pro." and does not select.
- [ ] **Language**: dropdown (English default, 22 languages).

Other nodes:
- [ ] **Run SEO Audit**: post dropdown = "Latest published post" + real published posts.
- [ ] **Enroll in Funnel**: funnel dropdown lists real funnels; "— Select —" placeholder until chosen; save blocked while empty.
- [ ] **Publish Social Post**: account dropdown lists only connected accounts (no separate platform field).
- [ ] **Custom Prompt**: prompt required.
- [ ] **Condition** (Pro): check dropdown has 3 readable options; node has yes/no output handles.

Workflow settings panel (no node selected):
- [ ] **Default tone** dropdown (same Pro gating).
- [ ] **Brand voice** select appears when Content module active; **[Free]** blocked with toast "Brand Voice is available in Pro."

## 5. Triggers

- [ ] Trigger node config lists: Schedule, Post published, Subscriber created, Chatbot lead.
- [ ] **Schedule**: set weekly + time → "Next run" appears in list & Upcoming Runs. **[Free]** only Once/Weekly allowed; Daily etc. rejected.
- [ ] **Once**: set run_at, save, rename workflow, save again → next_run_at unchanged.
- [ ] **Post published**: activate workflow, publish a post → run fires once (check History). Post type filter respected ("Any post type" default).
- [ ] **Subscriber created**: add subscriber in Email module → run fires; event email available to funnel-enroll step.
- [ ] **Chatbot lead**: capture lead via chatbot → run fires.
- [ ] Loop guard: workflow whose action publishes a post does NOT re-trigger itself.

## 6. Execution

- [ ] **Run now** (list or builder) → "queued" toast, status polls to running → finished without reload.
- [ ] **History**: per-step outputs with previews; artifact links (article, campaign, etc.) open correct edit screens.
- [ ] Blog step: article draft created in Content Generator with correct keywords, tone, language; brand voice styling applied when set (Pro).
- [ ] **Branching** (Pro): condition workflow — taken branch executes, untaken branch steps show "skipped / Branch not taken"; step counts add up.
- [ ] Failure policy: force a step failure (e.g. disconnect AI provider) → run marked failed; with Settings toggle ON admin receives failure email, OFF no email.
- [ ] **Upcoming Runs** lists scheduled workflows with next-run times.

## 7. Free plan limits **[Free]**

- [ ] Max 2 steps per workflow (3rd rejected with clear message, not silently dropped).
- [ ] Max 1 active workflow (activating 2nd rejected).
- [ ] Condition step / non-default branch rejected.

## 8. Regression

- [ ] Delete workflow → gone from list, executions cleaned.
- [ ] Deactivate/reactivate plugin → workflows, settings, schedules intact.
- [ ] No console errors in browser during any of the above.
