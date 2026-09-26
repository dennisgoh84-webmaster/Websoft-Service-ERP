// Key-in flows: the master records everything else hangs off --
// Setup Lists (the address pickers), and Company / Individual with its
// profile and a contact person.

import { apiGet, expectStored, keyIn, rows } from '../lib.mjs'
import { button, card, open, submit, tag } from './helpers.mjs'

export const setupLists = {
  name: 'Setup Lists: add a Country, then a State in it',
  screen: '/setup-lists',
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    const country = { code: `Z${t}`, name: `Selftest Land ${t}` }
    await open(page, '/setup-lists/country')
    let form = card(page, 'Add to')
    await keyIn(form, 'Code', country.code)
    await keyIn(form, 'Name', country.name)
    await submit(page, button(form, 'Add'), '/api/setup-lists')
    const countries = rows(await apiGet(page, '/setup-lists?list_type=country'))
    const c = countries.find((x) => x.code === country.code)
    if (!c) throw new Error(`Country ${country.code} was added but is not in the Country list.`)
    expectStored('Country name', c.name, country.name)

    const state = { code: `S${t}`, name: `Selftest State ${t}` }
    await open(page, '/setup-lists/state')
    form = card(page, 'Add to')
    await keyIn(form, 'Code', state.code)
    await keyIn(form, 'Name', state.name)
    await keyIn(form, 'Country', country.name)
    await submit(page, button(form, 'Add'), '/api/setup-lists')
    const s = rows(await apiGet(page, '/setup-lists?list_type=state')).find((x) => x.code === state.code)
    if (!s) throw new Error(`State ${state.code} was added but is not in the State list.`)
    expectStored('State name', s.name, state.name)
    expectStored("State's country", s.parent_code, country.code)
    shared.country = country
    shared.state = state
  },
}

export const companyIndividual = {
  name: 'Company / Individual: quick add, profile, contact person',
  screen: '/company-individuals',
  needs: ['country'],
  async run({ page, profileName, shared }) {
    const t = tag(profileName)
    await open(page, '/company-individuals')
    const add = card(page, 'Add Company / Individual')
    await keyIn(add, 'Type', 'Company')
    // Typed in lower case with stray spaces: the rule is FULL CAPITALS, tidied.
    await keyIn(add, 'Name', `  selftest   trading ${t.toLowerCase()} `)
    await keyIn(add, 'Email (optional)', `accounts.${t.toLowerCase()}@selftest.example`)
    await keyIn(add, 'Phone (optional)', '+65 6000 1234')
    await keyIn(add, 'Payment terms (days from invoice date)', '30')
    await add.getByLabel(/Is Supplier/).check()
    const created = await submit(page, button(add, 'Add Company / Individual'), '/api/company-individuals')

    const expectedName = `SELFTEST TRADING ${t}`
    const saved = await apiGet(page, `/company-individuals/${created.id}`)
    expectStored('Name (FULL CAPITALS, spaces tidied)', saved.name, expectedName)
    expectStored('Email', saved.billing_email, `accounts.${t.toLowerCase()}@selftest.example`)
    expectStored('Phone', saved.phone, '+65 6000 1234')
    expectStored('Payment terms', saved.payment_terms_days, '30')
    expectStored('Is Supplier', saved.is_supplier, 'true')

    // Its own page, through the list's link.
    await page.getByRole('link', { name: expectedName, exact: true }).first().click()
    await page.waitForURL(`**/company-individuals/${created.id}`)
    const profile = card(page, 'Profile')
    await keyIn(profile, 'UEN', `20${t.slice(-7)}K`)
    await keyIn(profile, 'GST registration no.', `M9-${t.slice(-7)}`)
    await keyIn(profile, 'Address line 1', '1 Selftest Road')
    await keyIn(profile, 'Country', shared.country.name)
    await keyIn(profile, 'State / Province', shared.state.name)
    await keyIn(profile, 'Postal code', '123456')
    await keyIn(profile, 'Purchase order approval limit (SGD)', '500')
    await keyIn(profile, 'Credit limit (SGD)', '5000')
    await submit(page, button(profile, 'Save changes'), `/api/company-individuals/${created.id}`, 'PATCH')
    const after = await apiGet(page, `/company-individuals/${created.id}`)
    expectStored('UEN', after.uen, `20${t.slice(-7)}K`)
    expectStored('GST registration no.', after.gst_registration_no, `M9-${t.slice(-7)}`)
    expectStored('Address line 1', after.address_line1, '1 Selftest Road')
    expectStored('Country', after.address_country, shared.country.name)
    expectStored('State', after.address_state, shared.state.name)
    expectStored('Postal code', after.address_postal_code, '123456')
    expectStored('Purchase order approval limit', Number(after.po_approval_limit_sgd), '500')
    expectStored('Credit limit', Number(after.credit_limit_sgd), '5000')

    const contactEmail = `kim.${t.toLowerCase()}@selftest.example`
    const contacts = card(page, 'Contact Person')
    await keyIn(contacts, 'Name', 'Kim Selftest')
    await keyIn(contacts, 'Email (optional)', contactEmail)
    await keyIn(contacts, 'Phone (optional)', '+65 9000 0001')
    await submit(page, button(contacts, 'Add contact person'), `/api/company-individuals/${created.id}/contacts`)
    const contact = rows(await apiGet(page, `/company-individuals/${created.id}/contacts`)).find((c) => c.email === contactEmail)
    if (!contact) throw new Error(`Contact ${contactEmail} was added but is not in the contact list.`)
    expectStored('Contact name', contact.name, 'Kim Selftest')
    expectStored('Contact phone', contact.phone, '+65 9000 0001')

    shared.customer = { id: created.id, name: expectedName, contactEmail }
  },
}
