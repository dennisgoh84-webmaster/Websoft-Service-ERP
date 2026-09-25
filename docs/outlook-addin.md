# Websoft Incidents: Outlook Add-in

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

## Status (2026-09-25)

**Built and tested, apart from the final step: loading it into a real
Outlook.** That step needs the server on an HTTPS address and a
Microsoft 365 account to upload the manifest to.

- **Served by the app itself** at `/outlook-addin/`
  (`frontend/public/outlook-addin/`, copied into the build). It is on
  the same address as the API, so no separate hosting and no cross-site
  setup is needed.
- **Maintenance → Outlook Add-in** (Core / Administration):
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
2. Open **Maintenance → Outlook Add-in** *from that HTTPS address* and
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
- `frontend/src/pages/OutlookAddinPage.tsx`: Maintenance → Outlook
  Add-in.
