import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import CompanySwitcher from './CompanySwitcher'
import NavSection, { type NavItem } from './NavSection'
import PromoVideoPanel from './PromoVideoPanel'
import ThemeToggle from './ThemeToggle'
import { useAuth } from '../lib/AuthContext'

const NAV_COLLAPSE_KEY = 'websoft_nav_collapsed'

function loadCollapsed(): Record<string, boolean> {
  try {
    const raw = localStorage.getItem(NAV_COLLAPSE_KEY)
    return raw ? JSON.parse(raw) : {}
  } catch {
    return {}
  }
}

/** Which module_key gates each nav link -- mirrors the `MODULE` constant
    at the top of each router (see app/routers/*.py). A link only shows
    when moduleAccess[key] is true: Group Authority grants at least VIEW
    AND Module Control has the module enabled for the active company
    (see app/services/authority.py / GET /api/modules/my-access). The
    owner role always reads as true for every built module (backend
    bypass mirrored there), so this never hides a link Dennis can
    actually use.

    Confirmed 2026-09-11: each section (Operations / Accounts /
    Maintenance) can be minimized independently -- a chevron toggle,
    state kept in localStorage (a per-browser display preference, not a
    permission, so it's fine to keep client-side only). This sits ON
    TOP of Group Authority, not instead of it: a section header itself
    only renders when at least one link inside it is visible to this
    user, exactly like the pre-existing Maintenance section already
    did -- collapsing a section never reveals anything a user's Group
    Authority already hides.

    Also confirmed 2026-09-11: "let me choose the sequence for the menu
    bar" -- each link within a section can be dragged to reorder it
    (see components/NavSection.tsx), independently per browser, on top
    of the same Group-Authority-filtered set -- reordering never
    reveals or hides anything. */
export default function Layout() {
  const { user, logout, activeCompany, moduleAccess } = useAuth()
  const location = useLocation()
  const can = (moduleKey: string) => moduleAccess[moduleKey] === true
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>(loadCollapsed)
  const [sidebarPeek, setSidebarPeek] = useState(false)

  // "After login successfully, all modules, hide menu bar" (2026-09-12)
  // -- sidebar is hidden by default everywhere, with the "☰ Menu" button
  // in the topbar letting you peek it back open without leaving the page.
  // "Menu bar should always be able to see this fixed: OPERATIONS /
  // STOCK INVENTORY / ACCOUNTS / MAINTENANCE" (2026-09-13) -- the 4
  // section titles are CSS sticky so they never scroll off-screen when
  // the sidebar is open.
  const sidebarHidden = !sidebarPeek

  // Re-hides the menu every time you land on a new page -- "peek" is a
  // per-visit override rather than a remembered preference.
  useEffect(() => {
    setSidebarPeek(false)
  }, [location.pathname])

  function toggleSection(key: string) {
    setCollapsed((prev) => {
      const next = { ...prev, [key]: !prev[key] }
      try {
        localStorage.setItem(NAV_COLLAPSE_KEY, JSON.stringify(next))
      } catch {
        /* private browsing / storage blocked -- collapse state just won't persist */
      }
      return next
    })
  }

  const operationsItems: NavItem[] = [
    { key: 'support-monitoring', path: '/support-monitoring', label: 'Support Monitoring', visible: can('reporting') },
    { key: 'company-individuals', path: '/company-individuals', label: 'Company / Individual', visible: can('company_individual_management') },
    { key: 'contracts', path: '/contracts', label: 'Service Contracts', visible: can('service_contracts') },
    { key: 'incidents', path: '/incidents', label: 'Incidents', visible: can('service_operations') },
    { key: 'job-orders', path: '/job-orders', label: 'Job Orders', visible: can('service_operations') },
    { key: 'service-records', path: '/service-records', label: 'Service Records', visible: can('service_records') },
    {
      key: 'service-record-approval',
      path: '/service-record-approval',
      label: 'Service Record Approval',
      visible: can('service_records'),
    },
    { key: 'approval-center', path: '/approval-center', label: 'Approval Center', visible: can('core_administration') },
    { key: 'excess-review', path: '/excess-review', label: 'Excess Review', visible: can('service_contracts') },
    { key: 'software-tasks', path: '/software-tasks', label: 'Software Tasks', visible: can('software_development') },
    { key: 'operations-reports', path: '/operations-reports', label: 'Operations Reports', visible: can('operations_reports') },
  ]

  // Order/labels below follow Dennis's requested sequence (2026-09-11):
  // Bank / Sales Quotation / Sales Invoice / Receipt Voucher / Purchase
  // Order / Accounts Payable / Payment Voucher / Journal Voucher / Chart
  // of Accounts / GST and Account Period. Two items in that list have no
  // dedicated route of their own, so they're mapped onto the closest
  // existing page rather than getting a confusing duplicate nav entry --
  // see docs/open-business-decisions.md for the full note:
  //   - "Purchase Order" -> /accounts-payable (POs are a section of that
  //     same page, not a separate route).
  //   - "GST and Account Period" -> /accounting-periods (GST Return
  //     itself stays a report under Accounting Reports, not moved).
  //
  // "Year-End Closing shift below GST and Account Period" (2026-09-11):
  // it used to be a card at the bottom of the Accounting Periods page;
  // split out to its own page (YearEndClosingPage.tsx) and nav entry,
  // placed directly under GST and Account Period, since closing a
  // fiscal year is a distinct, rare, owner-only action rather than
  // everyday period upkeep.
  const accountsItems: NavItem[] = [
    { key: 'bank-accounts', path: '/bank-accounts', label: 'Bank', visible: can('finance_accounting') },
    { key: 'quotations', path: '/quotations', label: 'Sales Quotation', visible: can('sales') },
    { key: 'invoices', path: '/invoices', label: 'Sales Invoice', visible: can('billing') },
    { key: 'receipts', path: '/receipts', label: 'Receipt Voucher', visible: can('accounts_receivable') },
    { key: 'purchase-orders', path: '/purchase-orders', label: 'Purchase Order', visible: can('accounts_payable') },
    { key: 'accounts-payable', path: '/accounts-payable', label: 'Accounts Payable', visible: can('accounts_payable') },
    { key: 'payment-voucher', path: '/payment-voucher', label: 'Payment Voucher', visible: can('accounts_payable') },
    { key: 'general-ledger', path: '/general-ledger', label: 'Journal Voucher', visible: can('finance_accounting') },
    { key: 'gl-transactions', path: '/gl-transactions', label: 'GL Transactions', visible: can('finance_accounting') },
    { key: 'chart-of-accounts', path: '/chart-of-accounts', label: 'Chart of Accounts', visible: can('finance_accounting') },
    { key: 'accounting-periods', path: '/accounting-periods', label: 'GST and Account Period', visible: can('finance_accounting') },
    { key: 'year-end-closing', path: '/year-end-closing', label: 'Year-End Closing', visible: can('finance_accounting') },
    { key: 'accounting-reports', path: '/accounting-reports', label: 'Accounting Reports', visible: can('accounting_reports') },
    { key: 'commission-payouts', path: '/commission-payouts', label: 'Commission Payouts', visible: can('accounting_reports') },
  ]

  const stockItems: NavItem[] = [
    { key: 'stock-master', path: '/stock-master', label: 'Stock Master', visible: can('stock_master') },
    { key: 'grn', path: '/grn', label: 'Goods Receive', visible: can('goods_receive_note') },
    { key: 'gtn', path: '/gtn', label: 'Goods Transfer', visible: can('goods_transfer_note') },
    { key: 'grtn', path: '/grtn', label: 'Goods Return', visible: can('goods_return_note') },
    { key: 'stock-adjustment', path: '/stock-adjustment', label: 'Stock Adjustment', visible: can('stock_adjustment') },
    { key: 'stock-reports', path: '/stock-reports', label: 'Stock Reports', visible: can('stock_operation_reports') },
  ]

  const maintenanceItems: NavItem[] = [
    { key: 'company-setup', path: '/company-setup', label: 'Company Setup', visible: can('core_administration') },
    {
      key: 'announcements',
      path: '/announcements',
      label: 'Announcements & Ad Banner',
      visible: can('core_administration'),
    },
    { key: 'system-email', path: '/system-email', label: 'System Email', visible: can('core_administration') },
    { key: 'ai-assistant', path: '/ai-assistant', label: 'AI Assistant', visible: can('core_administration') },
    {
      key: 'bank-portal-testing',
      path: '/bank-portal-testing',
      label: 'Bank Portal Testing',
      // Unlike AI Assistant's settings link (always reachable so an
      // owner can configure it before enabling), this one is gated on
      // its own module key exactly like a normal module: no staff
      // account sees it until Dennis switches `bank_portal_testing` on
      // for their company (the owner role still bypasses, same as
      // every other module). See BankPortalTestingPage.tsx.
      visible: can('bank_portal_testing'),
    },
    { key: 'staff', path: '/staff', label: 'Staff Master', visible: can('core_administration') },
    { key: 'groups', path: '/groups', label: 'Group Authority', visible: can('core_administration') },
    { key: 'product-catalog', path: '/product-catalog', label: 'Product Catalog', visible: can('sales') },
    // Setup Lists, one entry each (Dennis, 2026-09-15: "shift out into
    // the Maintenance Menu"); all one screen, keyed by the route.
    { key: 'setup-country', path: '/setup-lists/country', label: 'Country', visible: can('core_administration') },
    { key: 'setup-state', path: '/setup-lists/state', label: 'State / Province', visible: can('core_administration') },
    { key: 'setup-city', path: '/setup-lists/city', label: 'City', visible: can('core_administration') },
    { key: 'setup-nationality', path: '/setup-lists/nationality', label: 'Nationality', visible: can('core_administration') },
    { key: 'setup-area-code', path: '/setup-lists/area_code', label: 'Area Code', visible: can('core_administration') },
    { key: 'setup-currency', path: '/setup-lists/currency', label: 'Currency', visible: can('core_administration') },
    { key: 'setup-industry', path: '/setup-lists/industry', label: 'Industry', visible: can('core_administration') },
    { key: 'setup-product-category', path: '/setup-lists/product_category', label: 'Product Category', visible: can('sales') },
    { key: 'setup-uom', path: '/setup-lists/unit_of_measure', label: 'Unit of Measure', visible: can('sales') },
    { key: 'setup-relationship', path: '/setup-lists/relationship', label: 'Relationship', visible: can('company_individual_management') },
    { key: 'gl-types', path: '/gl-types', label: 'GL Types', visible: can('finance_accounting') },
    { key: 'reference-codes', path: '/reference-codes', label: 'Reference Monitor', visible: can('finance_accounting') },
    { key: 'tax-types', path: '/tax-types', label: 'Tax Types', visible: can('finance_accounting') },
    { key: 'currency-rates', path: '/currency-rates', label: 'Currency Rate Table', visible: can('finance_accounting') },
    { key: 'document-control', path: '/document-control', label: 'Document Control', visible: can('core_administration') },
    { key: 'warehouses', path: '/warehouses', label: 'Warehouses', visible: can('stock_master') },
    { key: 'stock-categories', path: '/stock-categories', label: 'Stock Categories', visible: can('stock_master') },
    { key: 'stock-groups', path: '/stock-groups', label: 'Stock Groups', visible: can('stock_master') },
    { key: 'stock-brands-models', path: '/stock-brands-models', label: 'Brands / Models', visible: can('stock_master') },
    { key: 'stock-usages', path: '/stock-usages', label: 'Stock Usages', visible: can('stock_master') },
    { key: 'approval-authorities', path: '/approval-authorities', label: 'Approval Authority', visible: can('core_administration') },
    { key: 'event-logs', path: '/event-logs', label: 'Event Logs', visible: can('event_logs') },
  ]

  return (
    <div className={`app-shell${sidebarHidden ? ' sidebar-hidden' : ''}`}>
      <nav className="sidebar">
        {/* Company logo sits above the product name -- it does not
            replace it. Set it in Company Setup. */}
        <div className="brand-block">
          {activeCompany?.logo && (
            <img
              className="company-logo"
              src={activeCompany.logo}
              alt={`${activeCompany.name} logo`}
            />
          )}
          <div className="brand">Websoft Service ERP</div>
          {activeCompany && <div className="brand-company">{activeCompany.name}</div>}
        </div>

        {/* Scrolls independently of the brand-block above and the
            user-info below once there are more links than fit one
            screen -- the menu is expected to keep growing as modules
            are added, so this must never depend on the viewport being
            tall enough. */}
        <div className="sidebar-nav">
          <NavLink to="/" end>
            Company Dashboard
          </NavLink>
          {can('reporting') && <NavLink to="/sales-dashboard">Sales Dashboard</NavLink>}
          {can('ops_dashboard') && <NavLink to="/ops-dashboard">My Ops Dashboard</NavLink>}

          <NavSection
            sectionKey="operations"
            title="Operations"
            items={operationsItems}
            collapsed={!!collapsed.operations}
            onToggle={() => toggleSection('operations')}
          />

          <NavSection
            sectionKey="accounts"
            title="Accounts"
            items={accountsItems}
            collapsed={!!collapsed.accounts}
            onToggle={() => toggleSection('accounts')}
          />

          <NavSection
            sectionKey="stock"
            title="Stock Inventory"
            items={stockItems}
            collapsed={!!collapsed.stock}
            onToggle={() => toggleSection('stock')}
          />

          <NavSection
            sectionKey="maintenance"
            title="Maintenance"
            items={maintenanceItems}
            collapsed={!!collapsed.maintenance}
            onToggle={() => toggleSection('maintenance')}
          />
        </div>

        <div className="user-info">
          <div>
            <strong>{user?.full_name}</strong>
          </div>
          <div>{user?.role}</div>
          <button className="secondary" style={{ marginTop: 10, width: '100%' }} onClick={logout}>
            Sign out
          </button>
        </div>
      </nav>
      <main className="main">
        <div className="main-topbar">
          <div className="main-topbar-left">
            <button
              type="button"
              className="secondary sidebar-peek-toggle"
              onClick={() => setSidebarPeek((v) => !v)}
            >
              {sidebarPeek ? '✕ Hide menu' : '☰ Menu'}
            </button>
          </div>
          <div className="main-topbar-right">
            <CompanySwitcher />
            <ThemeToggle />
          </div>
        </div>
        <Outlet />
      </main>
      <PromoVideoPanel className="app-ad-banner" />
    </div>
  )
}
