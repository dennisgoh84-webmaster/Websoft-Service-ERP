// Key-in flows: the AI Assistant (Backlog 2, 2026-09-26) -- the
// fallback model on the settings screen, and "Draft with AI" by a
// Service Record's work description. The self-test switches every
// module on, the paid AI add-on included.

import { apiGet, expectStored, field, keyIn } from '../lib.mjs'
import { button, card, open, submit, tag } from './helpers.mjs'

export const aiAssistant = {
  name: 'AI Assistant: fallback model saved; Draft with AI on a Service Record',
  screen: '/ai-assistant',
  needs: ['jobOrder'],
  async run({ page, watcher, profileName, shared }) {
    const fallback = profileName === 'phone' ? 'claude-haiku-4-5' : 'claude-sonnet-5'
    await open(page, '/ai-assistant')
    const f = card(page, 'Model provider')
    await keyIn(f, 'Fallback model', fallback)
    await submit(page, button(f, 'Save settings'), '/api/ai/settings', 'PATCH')
    const s = await apiGet(page, '/ai/settings')
    expectStored('Fallback model', s.fallback_model, fallback)

    // Draft with AI: rough notes in Malay and shorthand. On a server
    // with no API key (the usual self-test) the reason shows by the
    // button and the notes stay as typed; with a key, the draft
    // replaces them.
    const notes = `tukar toner cartridge, test print ok ${tag(profileName)}`
    await open(page, `/job-orders/${shared.jobOrder.id}`)
    const sr = card(page, 'Log a Service Record')
    await keyIn(sr, 'Work description (optional)', notes)
    const [res] = await Promise.all([
      page.waitForResponse((r) => new URL(r.url()).pathname === '/api/ai/draft-work-description', { timeout: 60000 }),
      button(sr, '✨ Draft with AI').click(),
    ])
    const box = field(sr, 'Work description (optional)')
    const body = await res.json().catch(() => ({}))
    if (res.ok() && body.description) {
      for (let i = 0; i < 50 && (await box.inputValue()) !== body.description; i++) await page.waitForTimeout(100)
      expectStored('Work description after drafting', await box.inputValue(), body.description)
    } else {
      await sr.getByText(body.detail ?? body.refusal_reason ?? 'could not draft').first().waitFor({ timeout: 10000 })
      expectStored('Notes kept when drafting fails', await box.inputValue(), notes)
      watcher.expect((f) => f.path === '/api/ai/draft-work-description')
    }
  },
}
