// Detect if user is on a mobile device and should use mobile-optimized interface
export function isMobileDevice(): boolean {
  // Check user agent for common mobile patterns
  const userAgent = navigator.userAgent.toLowerCase()
  const mobilePatterns = [
    /iphone|ipad|ipod/,
    /android/,
    /windows phone/,
    /blackberry/,
    /opera mini/,
    /iemobile/,
  ]
  return mobilePatterns.some(pattern => pattern.test(userAgent))
}

// Check if viewport is mobile-sized (useful for responsive designs)
export function isMobileViewport(): boolean {
  return window.innerWidth < 768
}

function isPhone(): boolean {
  return isMobileDevice() || isMobileViewport()
}

// Which version this phone opens after sign-in (Dennis, 2026-09-25: a
// phone was always forced into /mobile with no way to reach the full
// ERP). The full ERP is the default; the Mobile App is a choice made from
// the ERP's top bar and remembered on this device until "Full site" is
// tapped in the Mobile App.
const VIEW_KEY = 'erp_view_preference'
export type ViewPreference = 'mobile' | 'full'

export function getViewPreference(): ViewPreference {
  try {
    return localStorage.getItem(VIEW_KEY) === 'mobile' ? 'mobile' : 'full'
  } catch {
    return 'full'
  }
}

export function setViewPreference(view: ViewPreference): void {
  try {
    localStorage.setItem(VIEW_KEY, view)
  } catch {
    // Private browsing etc. -- the switch still works for this visit.
  }
}

/** Offer the "Mobile app" switch at all? Only on a phone-sized screen or device. */
export function canUseMobileApp(): boolean {
  return isPhone()
}

// Redirect into the mobile app only when this phone chose it; a desktop
// never is, whatever was stored.
export function shouldUseMobileApp(): boolean {
  return isPhone() && getViewPreference() === 'mobile'
}
