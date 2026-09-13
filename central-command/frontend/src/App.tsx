import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './lib/AuthContext'
import Layout from './components/Layout'
import LoginPage from './pages/LoginPage'
import DashboardPage from './pages/DashboardPage'
import ClientsPage from './pages/ClientsPage'
import ClientDetailPage from './pages/ClientDetailPage'
import AdvertisementsPage from './pages/AdvertisementsPage'
import ConfigUpdatesPage from './pages/ConfigUpdatesPage'
import VersionControlPage from './pages/VersionControlPage'
import StaffPage from './pages/StaffPage'

export default function App() {
  const { user, loading } = useAuth()

  if (loading) return <p style={{ padding: 40 }}>Loading...</p>

  if (!user) {
    return (
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="*" element={<Navigate to="/login" />} />
      </Routes>
    )
  }

  return (
    <Layout>
      <Routes>
        <Route path="/" element={<DashboardPage />} />
        <Route path="/clients" element={<ClientsPage />} />
        <Route path="/clients/:id" element={<ClientDetailPage />} />
        <Route path="/advertisements" element={<AdvertisementsPage />} />
        <Route path="/config-updates" element={<ConfigUpdatesPage />} />
        <Route path="/versions" element={<VersionControlPage />} />
        <Route path="/staff" element={<StaffPage />} />
        <Route path="*" element={<Navigate to="/" />} />
      </Routes>
    </Layout>
  )
}
