import type { ReactElement } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import Layout from './components/Layout'
import AiDataConsentGate from './components/AiDataConsentGate'
import { AuthProvider, useAuth } from './lib/AuthContext'
import { ThemeProvider } from './lib/ThemeContext'
import { shouldUseMobileApp } from './lib/mobileDetect'
import AccountingPeriodsPage from './pages/AccountingPeriodsPage'
import ApprovalAuthoritiesPage from './pages/ApprovalAuthoritiesPage'
import ApprovalCenterPage from './pages/ApprovalCenterPage'
import AccountingReportsPage from './pages/AccountingReportsPage'
import AnnouncementsPage from './pages/AnnouncementsPage'
import SystemEmailPage from './pages/SystemEmailPage'
import AiAssistantPage from './pages/AiAssistantPage'
import AccountsPayablePage from './pages/AccountsPayablePage'
import BankAccountDetailPage from './pages/BankAccountDetailPage'
import BankAccountsPage from './pages/BankAccountsPage'
import BankPortalTestingPage from './pages/BankPortalTestingPage'
import CommissionPayoutsPage from './pages/CommissionPayoutsPage'
import ChartOfAccountsPage from './pages/ChartOfAccountsPage'
import CurrencyRatesPage from './pages/CurrencyRatesPage'
import DocumentControlPage from './pages/DocumentControlPage'
import GeneralLedgerPage from './pages/GeneralLedgerPage'
import GLTransactionsPage from './pages/GLTransactionsPage'
import GLTypesPage from './pages/GLTypesPage'
import CompanySetupPage from './pages/CompanySetupPage'
import ContractDetailPage from './pages/ContractDetailPage'
import ContractsPage from './pages/ContractsPage'
import CompanyIndividualDetailPage from './pages/CompanyIndividualDetailPage'
import CompanyIndividualsPage from './pages/CompanyIndividualsPage'
import ProspectActivitiesPage from './pages/ProspectActivitiesPage'
import ProspectActivityDetailPage from './pages/ProspectActivityDetailPage'
import ProspectDetailPage from './pages/ProspectDetailPage'
import ProspectsPage from './pages/ProspectsPage'
import DashboardPage from './pages/DashboardPage'
import SalesDashboardPage from './pages/SalesDashboardPage'
import EventLogsPage from './pages/EventLogsPage'
import ConnectAddinPage from './pages/ConnectAddinPage'
import EmailAddinsPage from './pages/EmailAddinsPage'
import DataMigrationBatchLogPage from './pages/DataMigrationBatchLogPage'
import DataMigrationDashboardPage from './pages/DataMigrationDashboardPage'
import DataMigrationImportPage from './pages/DataMigrationImportPage'
import DataMigrationModulesPage from './pages/DataMigrationModulesPage'
import ExcessReviewPage from './pages/ExcessReviewPage'
import GroupsPage from './pages/GroupsPage'
import InvoicePrintPage from './pages/InvoicePrintPage'
import InvoicesPage from './pages/InvoicesPage'
import JobOrderDetailPage from './pages/JobOrderDetailPage'
import JobOrderPrintPage from './pages/JobOrderPrintPage'
import IncidentsPage from './pages/IncidentsPage'
import EmailInboxPage from './pages/EmailInboxPage'
import JobOrdersPage from './pages/JobOrdersPage'
import Login from './pages/Login'
import MobileApp from './pages/MobileApp'
import PortalApp from './portal/PortalApp'
import OperationsReportsPage from './pages/OperationsReportsPage'
import OpsDashboardPage from './pages/OpsDashboardPage'
import PaymentVoucherPage from './pages/PaymentVoucherPage'
import PaymentVoucherPrintPage from './pages/PaymentVoucherPrintPage'
import ProductCatalogPage from './pages/ProductCatalogPage'
import PurchaseOrderPrintPage from './pages/PurchaseOrderPrintPage'
import PurchaseOrdersPage from './pages/PurchaseOrdersPage'
import QuotationPrintPage from './pages/QuotationPrintPage'
import QuotationsPage from './pages/QuotationsPage'
import ReceiptPrintPage from './pages/ReceiptPrintPage'
import ReceiptsPage from './pages/ReceiptsPage'
import ReferenceCodesPage from './pages/ReferenceCodesPage'
import ServiceRecordApprovalPage from './pages/ServiceRecordApprovalPage'
import ServiceRecordPrintPage from './pages/ServiceRecordPrintPage'
import ServiceRecordsPage from './pages/ServiceRecordsPage'
import SetupListsPage from './pages/SetupListsPage'
import SoftwareTasksPage from './pages/SoftwareTasksPage'
import StaffDetailPage from './pages/StaffDetailPage'
import StaffMasterPage from './pages/StaffMasterPage'
import SupportMonitoringPage from './pages/SupportMonitoringPage'
import TaxTypesPage from './pages/TaxTypesPage'
import GoodsReceiveNotePage from './pages/GoodsReceiveNotePage'
import GoodsIssueNotePage from './pages/GoodsIssueNotePage'
import GoodsReturnNotePage from './pages/GoodsReturnNotePage'
import GoodsTransferNotePage from './pages/GoodsTransferNotePage'
import StockAdjustmentPage from './pages/StockAdjustmentPage'
import StockBrandModelPage from './pages/StockBrandModelPage'
import StockCategoryPage from './pages/StockCategoryPage'
import StockGroupPage from './pages/StockGroupPage'
import StockItemDetailPage from './pages/StockItemDetailPage'
import StockMasterPage from './pages/StockMasterPage'
import StockReportsPage from './pages/StockReportsPage'
import StockUsagePage from './pages/StockUsagePage'
import WarehousesPage from './pages/WarehousesPage'
import YearEndClosingPage from './pages/YearEndClosingPage'

function RequireAuth({ children }: { children: ReactElement }) {
  const { user, loading, refresh } = useAuth()
  const location = useLocation()
  if (loading) return <p style={{ padding: 24 }}>Loading...</p>
  // Remembers where the user was going (e.g. /connect-addin, opened
  // from the Gmail add-on), so signing in lands back there.
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
  // PDPA self-declaration for the AI Assistant (2026-09-15): blocks
  // every screen until acknowledged, once, per user -- see
  // AiDataConsentGate.tsx.
  if (user.ai_data_consent_required) return <AiDataConsentGate onAcknowledged={refresh} />
  return children
}

function MobileRedirect({ children }: { children: ReactElement }) {
  const { user, loading } = useAuth()
  if (loading) return <p style={{ padding: 24 }}>Loading...</p>
  if (!user) return children
  // Redirect authenticated mobile users to /mobile
  if (shouldUseMobileApp()) return <Navigate to="/mobile" replace />
  return children
}

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      {/* Mobile web app: separate entry point, no sidebar/desktop layout */}
      <Route path="/mobile" element={<MobileApp />} />
      {/* Customer Helpdesk Portal (PORTAL-001..004): its own auth entirely
          (own token, own login/OTP/change-password sequence -- see
          src/lib/PortalAuthContext.tsx) so it never touches the staff
          RequireAuth/Layout below. PortalApp handles its own internal
          "pages" (home/contracts/job orders/incidents) without further
          sub-routes here, the same way MobileApp does above. */}
      <Route path="/portal/*" element={<PortalApp />} />
      {/* Gmail add-on connect code: a page of its own, outside the
          desktop layout and the mobile redirect, since it is opened from
          the add-on's "Get a code" link on any device. */}
      <Route
        path="/connect-addin"
        element={
          <RequireAuth>
            <ConnectAddinPage />
          </RequireAuth>
        }
      />
      <Route
        element={
          <MobileRedirect>
            <RequireAuth>
              <Layout />
            </RequireAuth>
          </MobileRedirect>
        }
      >
        <Route path="/" element={<DashboardPage />} />
        <Route path="/sales-dashboard" element={<SalesDashboardPage />} />
        <Route path="/ops-dashboard" element={<OpsDashboardPage />} />
        <Route path="/company-individuals" element={<CompanyIndividualsPage />} />
        <Route path="/company-individuals/:id" element={<CompanyIndividualDetailPage />} />
        <Route path="/prospects" element={<ProspectsPage />} />
        <Route path="/prospects/:id" element={<ProspectDetailPage />} />
        <Route path="/prospect-activities" element={<ProspectActivitiesPage />} />
        <Route path="/prospect-activities/:activityId" element={<ProspectActivityDetailPage />} />
        <Route path="/contracts" element={<ContractsPage />} />
        <Route path="/contracts/:id" element={<ContractDetailPage />} />
        <Route path="/incidents" element={<IncidentsPage />} />
        <Route path="/email-inbox" element={<EmailInboxPage />} />
        <Route path="/job-orders" element={<JobOrdersPage />} />
        <Route path="/job-orders/:id" element={<JobOrderDetailPage />} />
        <Route path="/job-orders/:id/print" element={<JobOrderPrintPage />} />
        <Route path="/service-records" element={<ServiceRecordsPage />} />
        <Route path="/service-records/:id/print" element={<ServiceRecordPrintPage />} />
        <Route path="/service-record-approval" element={<ServiceRecordApprovalPage />} />
        <Route path="/support-monitoring" element={<SupportMonitoringPage />} />
        <Route path="/software-tasks" element={<SoftwareTasksPage />} />
        <Route path="/excess-review" element={<ExcessReviewPage />} />
        <Route path="/operations-reports" element={<OperationsReportsPage />} />
        <Route path="/quotations" element={<QuotationsPage />} />
        <Route path="/quotations/:id/print" element={<QuotationPrintPage />} />
        <Route path="/invoices" element={<InvoicesPage />} />
        <Route path="/invoices/:id/print" element={<InvoicePrintPage />} />
        <Route path="/receipts" element={<ReceiptsPage />} />
        <Route path="/receipts/:id/print" element={<ReceiptPrintPage />} />
        <Route path="/purchase-orders" element={<PurchaseOrdersPage />} />
        <Route path="/purchase-orders/:id/print" element={<PurchaseOrderPrintPage />} />
        <Route path="/accounts-payable" element={<AccountsPayablePage />} />
        <Route path="/payment-voucher" element={<PaymentVoucherPage />} />
        <Route path="/payment-voucher/:id/print" element={<PaymentVoucherPrintPage />} />
        <Route path="/chart-of-accounts" element={<ChartOfAccountsPage />} />
        <Route path="/reference-codes" element={<ReferenceCodesPage />} />
        <Route path="/gl-types" element={<GLTypesPage />} />
        <Route path="/currency-rates" element={<CurrencyRatesPage />} />
        <Route path="/bank-accounts" element={<BankAccountsPage />} />
        <Route path="/bank-accounts/:id" element={<BankAccountDetailPage />} />
        <Route path="/tax-types" element={<TaxTypesPage />} />
        <Route path="/accounting-periods" element={<AccountingPeriodsPage />} />
        <Route path="/year-end-closing" element={<YearEndClosingPage />} />
        <Route path="/general-ledger" element={<GeneralLedgerPage />} />
        <Route path="/gl-transactions" element={<GLTransactionsPage />} />
        <Route path="/accounting-reports" element={<AccountingReportsPage />} />
        <Route path="/commission-payouts" element={<CommissionPayoutsPage />} />
        <Route path="/company-setup" element={<CompanySetupPage />} />
        <Route path="/announcements" element={<AnnouncementsPage />} />
        <Route path="/system-email" element={<SystemEmailPage />} />
        <Route path="/ai-assistant" element={<AiAssistantPage />} />
        <Route path="/bank-portal-testing" element={<BankPortalTestingPage />} />
        <Route path="/staff" element={<StaffMasterPage />} />
        <Route path="/staff/:id" element={<StaffDetailPage />} />
        <Route path="/groups" element={<GroupsPage />} />
        <Route path="/product-catalog" element={<ProductCatalogPage />} />
        <Route path="/setup-lists" element={<SetupListsPage />} />
        <Route path="/setup-lists/:listType" element={<SetupListsPage />} />
        <Route path="/document-control" element={<DocumentControlPage />} />
        <Route path="/approval-authorities" element={<ApprovalAuthoritiesPage />} />
        <Route path="/approval-center" element={<ApprovalCenterPage />} />
        <Route path="/event-logs" element={<EventLogsPage />} />
        <Route path="/maintenance/email-addins" element={<EmailAddinsPage />} />
        <Route path="/maintenance/outlook-addin" element={<Navigate to="/maintenance/email-addins" replace />} />
        <Route path="/data-migration" element={<DataMigrationDashboardPage />} />
        <Route path="/data-migration/modules" element={<DataMigrationModulesPage />} />
        <Route path="/data-migration/import" element={<DataMigrationImportPage />} />
        <Route path="/data-migration/batches" element={<DataMigrationBatchLogPage />} />
        <Route path="/warehouses" element={<WarehousesPage />} />
        <Route path="/stock-master" element={<StockMasterPage />} />
        <Route path="/stock-master/:id" element={<StockItemDetailPage />} />
        <Route path="/grn" element={<GoodsReceiveNotePage />} />
        <Route path="/gtn" element={<GoodsTransferNotePage />} />
        <Route path="/grtn" element={<GoodsReturnNotePage />} />
        <Route path="/gin" element={<GoodsIssueNotePage />} />
        <Route path="/stock-adjustment" element={<StockAdjustmentPage />} />
        <Route path="/stock-reports" element={<StockReportsPage />} />
        <Route path="/stock-categories" element={<StockCategoryPage />} />
        <Route path="/stock-groups" element={<StockGroupPage />} />
        <Route path="/stock-brands-models" element={<StockBrandModelPage />} />
        <Route path="/stock-usages" element={<StockUsagePage />} />
      </Route>
    </Routes>
  )
}

export default function App() {
  return (
    <ThemeProvider>
      <BrowserRouter>
        <AuthProvider>
          <AppRoutes />
        </AuthProvider>
      </BrowserRouter>
    </ThemeProvider>
  )
}
