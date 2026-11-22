# Brief (NOT BUILT YET): links for resources and work in the Action Plan

Agreed in chat 22 Nov 2026. Do not build until the owner sends the live files listed at the bottom.

## What the owner wants
Like the Internship tool: the supervisor shares links (ebook, video, Google folder) with the person, and the person shares links to their own work (a Google Drive file or folder).

## Plan (links only: no file upload, no Google connection)
- **Resources from the supervisor.** Any task can carry links: title, URL, type (ebook / video / folder / other). Set on the template for everyone (super admin), and a coach can add links for one agent. The agent sees them as buttons on the task, opening in a new tab (`rel="noopener"`).
- **Work from the agent.** On a task the agent pastes a link to their work (title, URL, optional note). Several per task. The agent can remove one until it is approved. The coach sees them with the task and approves or sends back as now.
- **One work folder per plan.** Optional link the agent saves once; the coach sees it at the top of the plan.
- **Privacy.** Only the agent, their coach and the super admin see an agent's work links. Not buddies, not other team admins.
- **Checks.** Server accepts only `https://` URLs of sensible length. It cannot tell whether the link is shared with the coach, so show a reminder beside the field: set Google sharing to "Anyone with the link can view" or add the coach.
- Internship stays as it is. Reuse its look and rules for links.
- New database script (next number: `schema_phase18.sql` unless Internship used it), new API actions, audit log entries. Keep the usual rules: 4xx errors only, unique `ap_` prefixes, no secrets in files.

## Files the owner must send first (live copies)
1. `private/lib/actionplan.php` (changed by the missions update)
2. `public_html/members/action-plan/index.html`
3. From Internship: `public_html/members/internship/index.html` and any `.php` it uses for links (in `public_html/members/api/` or `private/lib/`)
4. Whether Internship added a database script after phase 17
