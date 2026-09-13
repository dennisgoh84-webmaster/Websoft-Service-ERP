import type { ReactElement } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import Layout from './components/Layout'
import { AuthProvider, useAuth } from './lib/AuthContext'
import { ThemeProvider } from './lib/ThemeContext'
import AccountingPeriodsPage from './pages/AccountingPeriodsPage'
import ApprovalAuthoritiesPage from './pages/ApprovalAuthoritiesPage'
import ApprovalCenterPage from './pages/ApprovalCenterPage'
import AccountingReportsPage from './pages/AccountingReportsPage'
import AnnouncementsPage from './pages/AnnouncementsPage'
import AccountsPayablePage from './pages/AccountsPayablePage'
import BankAccountDetailPage from './pages/BankAccountDetailPage'
import BankAccountsPage from './pages/BankAccountsPage'
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
import DashboardPage from './pages/DashboardPage'
import EventLogsPage from './pages/EventLogsPage'
import ExcessReviewPage from './pages/ExcessReviewPage'
import GroupsPage from './pages/GroupsPage'
import InvoicePrintPage from './pages/InvoicePrintPage'
import InvoicesPage from './pages/InvoicesPage'
import JobOrderDetailPage from './pages/JobOrderDetailPage'
import JobOrderPrintPage from './pages/JobOrderPrintPage'
import IncidentsPage from './pages/IncidentsPage'
import JobOrdersPage from './pages/JobOrdersPage'
import Login from './pages/Login'
import MobileApp from './pages/MobileApp'
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
import YearEndClosingPage from './pages/YearEndClosingPage'

function RequireAuth({ children }: { children: ReactElement }) {
  const { user, loading } = useAuth()
  if (loading) return <p style={{ padding: 24 }}>Loading...</p>
  if (!user) return <Navigate to="/login" replace />
  return children
}

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      {/* Mobile web app: separate entry point, no sidebar/desktop layout */}
      <Route path="/mobile" element={<MobileApp />} />
      <Route
        element={
          <RequireAuth>
            <Layout />
          </RequireAuth>
        }
      >
        <Route path="/" element={<DashboardPage />} />
        <Route path="/ops-dashboard" element={<OpsDashboardPage />} />
        <Route path="/company-individuals" element={<CompanyIndividualsPage />} />
        <Route path="/company-individuals/:id" element={<CompanyIndividualDetailPage />} />
        <Route path="/contracts" element={<ContractsPage />} />
        <Route path="/contracts/:id" element={<ContractDetailPage />} />
        <Route path="/incidents" element={<IncidentsPage />} />
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
        <Route path="/staff" element={<StaffMasterPage />} />
        <Route path="/staff/:id" element={<StaffDetailPage />} />
        <Route path="/groups" element={<GroupsPage />} />
        <Route path="/product-catalog" element={<ProductCatalogPage />} />
        <Route path="/setup-lists" element={<SetupListsPage />} />
        <Route path="/document-control" element={<DocumentControlPage />} />
        <Route path="/approval-authorities" element={<ApprovalAuthoritiesPage />} />
        <Route path="/approval-center" element={<ApprovalCenterPage />} />
        <Route path="/event-logs" element={<EventLogsPage />} />
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
