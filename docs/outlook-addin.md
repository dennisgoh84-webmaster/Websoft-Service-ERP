# Websoft Incidents: Outlook Add-in and Gmail add-on

A task-pane add-in that puts two buttons on an open email: **Log as
Incident** and **Convert to Job Order**. Both call this app's own
`/api/incidents/from-email` and `/api/incidents/from-email/convert-to-job-order`
endpoints (`IncidentController`). The confirmed rules for what these do
are in `docs/open-business-decisions.md` #36:
- The sender is matched to a Company / Individual by a Contact person's
  email.
- A Job Order needs an active contract. Without one, the email falls back
  to a plain Incident and the add-in says why.
- The sender is acknowledged from the Helpdesk mailbox.

There is a **Gmail twin** (below), built 2026-09-25 so the helpdesk can
use whichever mail client its inbox is read in. Both are set up from
**Maintenance → Email Add-ins** (`/maintenance/email-addins`; the old
`/maintenance/outlook-addin` address redirects there).

## Without HTTPS: the Email Inbox (2026-09-26)

Both add-ins need a public HTTPS address. Until the server has one,
**Operations → Email Inbox** does the same job from inside the app. The
server signs in to the helpdesk mailbox over IMAP, using the IMAP
settings under Maintenance → System Email (Helpdesk), and lists each new
email. Staff press **Log as Incident** or **Convert to Job Order**, the
same logic and acknowledgement email as the add-ins, or **Dismiss** it
with a reason.

- **Checking:** the mailbox is checked when the screen is opened, at
  most once a minute, and on **Check now**.
  `php artisan email-inbox:check` does the same from a cron.
- **The mailbox itself is never changed:** nothing is marked read,
  moved or deleted there.
- **First read:** it goes back 7 days, then carries on from the last
  email read.
- **Attachments** stay in the mailbox and are named on the Incident.

See `App\Services\EmailInbox`, tested in `tests/Feature/EmailInboxTest.php`.

## Status (2026-09-25)

**Built and tested, apart from the final step: loading it into a real
Outlook.** That step needs the server on an HTTPS address and a
Microsoft 365 account to upload the manifest to.

- **Served by the app itself** at `/outlook-addin/`
  (`frontend/public/outlook-addin/`, copied into the build). It is on
  the same address as the API, so no separate hosting and no cross-site
  setup is needed.
- **Maintenance → Email Add-ins** (Core / Administration):
  - shows whether this server is on HTTPS and whether the add-in files
    are present;
  - downloads the manifest **already filled in with this server's
    address**, so no one edits XML by hand;
  - lists the install steps.
- **Sign-in follows the same steps as the web app's login page:**
  - email and password;
  - then, if one is set up, the one-time sign-in code (OTP), including
    the Email / WhatsApp choice when both are available.
  - "must change password" and the PDPA declaration are explained,
    never silently failed.
  - An expired session returns to the sign-in panel.
- **Tested in a browser, with a stand-in for Outlook that supplies an
  open email**, against the real backend:
  - a wrong password;
  - sign in;
  - Log as Incident;
  - Convert to Job Order, both when the Job Order is created and when it
    falls back to a plain Incident;
  - an expired session.

  The sign-in-code path was tested with scripted server replies.

  What was not tested is Outlook's own window around the panel, which
  only exists inside Outlook.

## To switch it on

1. Put the server on HTTPS (DEPLOY.md section 4: a domain with a
   certificate, or a tunnel). Outlook refuses plain-HTTP add-ins.
2. Open **Maintenance → Email Add-ins** *from that HTTPS address* and
   click **Download manifest**.
3. Load the manifest into Outlook:
   - **Whole company:** a Microsoft 365 administrator uploads it under
     Microsoft 365 admin center → Settings → Integrated apps → Upload
     custom apps. It can take up to a day to reach everyone.
   - **Just yourself, to try it:** Outlook on the web → an email →
     … → Get Add-ins → My add-ins → Add a custom add-in → Add from file.
4. Open an email and click **Log Incident**. Sign in once per Outlook
   session.

Staff need **Edit** access to Helpdesk / Service Operations
(`service_operations`) to use the two buttons.

## Design choices

- **The add-in uses this app's own login, not Azure AD / Office SSO.**
  SSO would skip the sign-in panel for someone already signed in to
  Outlook. But it needs an Azure AD app registration configured against
  your real tenant, and it isn't needed for the add-in to work. It stays
  an optional later step.
- **The token is kept only in the task pane's `sessionStorage`,** so
  closing Outlook signs the add-in out.
- **The manifest keeps `{{HOST}}` placeholders in the repository.** The
  Maintenance page fills them in from the address it is opened on. The
  add-in's `Id` is fixed, so re-uploading an updated manifest updates the
  existing add-in rather than adding a second one; raise `<Version>` when
  the task pane changes.

## Files

- `frontend/public/outlook-addin/manifest.xml`: the classic XML Office
  Add-in manifest (a template: `{{HOST}}` is filled in on download).
- `frontend/public/outlook-addin/taskpane.html` / `taskpane.js`: the
  panel. It loads `Office.js` from Microsoft's own CDN, which every Office
  Add-in must do.
- `frontend/public/outlook-addin/assets/icon-{16,32,80}.png`: plain
  maroon placeholder icons. Replace them with real artwork if wanted.
- `frontend/src/pages/EmailAddinsPage.tsx`: Maintenance → Email
  Add-ins (Outlook and Gmail).

## Gmail add-on

A Google Workspace add-on written in Google Apps Script: a **Websoft
Incidents** panel on the right of Gmail with the same two buttons,
calling the same two endpoints, so the rules above apply unchanged.

- **Sign-in is a one-time connect code, never a password.** A Gmail
  add-on panel has no password box (a password would show on screen
  as it is typed), and a password should not pass through Google's
  servers anyway. The panel's **Get a code** link opens
  `/connect-addin` in the Websoft web app. That is a page of its own
  outside the desktop layout, so it works from a phone too. Sign-in
  there is the normal one, including the sign-in code and the PDPA
  declaration, and it returns to the page afterwards. The user clicks
  **Get a code** and types the 8-character code into the panel.
  - `POST /api/auth/addin-connect-code` (signed in) issues the code.
    It is kept in `login_otps` as a SHA-256 hash under purpose
    `addin_connect`, lasts 10 minutes, and works once. Asking again
    cancels the previous code.
  - `POST /api/auth/addin-connect` (rate-limited to 10 a minute)
    trades the code for an ordinary access token.
  - Both are recorded in Event Logs (`addin_connect_code_issued`,
    `signed_in_via_addin`). Tested in `tests/Feature/AddinConnectTest.php`.
- The token is kept in the script's per-user properties until the
  Websoft session ends (`JWT_ACCESS_TOKEN_EXPIRE_MINUTES`, 8 hours by
  default). After that, the next click asks for a new code.
- **HTTPS is needed here too.** Google only lets an add-on fetch
  addresses listed in `urlFetchWhitelist`, and those must be https://.
  Unlike Outlook, the calls come from Google's servers rather than
  the user's browser, so the server must be reachable from the
  internet.
- **The address must be public, not just https.** Apps Script refuses
  to save an `appsscript.json` whose address is not a public https://
  one (found 2026-09-26, testing against the office server's
  `https://192.168.0.188:8443`). And Google could not reach an
  office-only address anyway. So Maintenance → Email Add-ins shows a
  "Reachable by Google" row and only offers the Gmail files when
  opened from a public https:// address. It treats as office-only:
  localhost, 10.x, 172.16–31.x, 192.168.x, 100.64–127.x, and `.local`
  / `.lan` / `.internal` names. For a quick test, a Cloudflare quick
  tunnel (`cloudflared tunnel --url http://localhost:<port>`) gives a
  public `https://….trycloudflare.com` address with no account.
- **Installing:** Maintenance → Email Add-ins downloads `Code.gs` and
  `appsscript.json`, filled in with the server's address. Paste both
  into a new project at script.google.com, then install it.
  - **Test deployment:** Deploy → Test deployments → Install puts it
    in that one Google account. This is the only route for a personal
    @gmail.com account.
  - **Whole Google Workspace company:** a Workspace administrator
    publishes it privately through the Google Workspace Marketplace SDK.
- **Tested:** the real `Code.gs` was run in Node against the running
  backend over HTTPS. Google's own services (`CardService`,
  `UrlFetchApp`, `GmailApp`, `PropertiesService`) were stand-ins
  covering:
  - connect, including a wrong code and a reused code;
  - Log as Incident;
  - Convert to Job Order, both created and falling back to a plain
    Incident;
  - an ended session;
  - an email whose sender and subject contain `&` and `<`.

  **Not tested:** Gmail itself, and Google's consent screen, which only
  exist inside a Google account.

Files: `frontend/public/gmail-addon/Code.gs` and `appsscript.json`
(templates: `{{BASE_URL}}` is filled in on download), and
`frontend/src/pages/ConnectAddinPage.tsx`.
