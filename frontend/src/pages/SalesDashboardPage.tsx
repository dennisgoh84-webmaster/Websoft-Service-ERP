// Standalone Sales Dashboard page, per Dennis's explicit request
// (2026-09-14) that it not be merged into the Company Dashboard --
// it's its own item on the Main Menu, between Company Dashboard and
// My Ops Dashboard. Was originally embedded directly in
// DashboardPage.tsx; the section component itself (and its own
// backend, App\Services\SalesDashboardService) is unchanged, only
// where it's mounted moved. SalesDashboardSection renders its own
// <h2>Sales Dashboard</h2> heading and subtitle, so this wrapper adds
// nothing else.
import SalesDashboardSection from '../components/SalesDashboardSection'

export default function SalesDashboardPage() {
  return <SalesDashboardSection />
}
