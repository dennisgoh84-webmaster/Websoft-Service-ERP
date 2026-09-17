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

// Should we use mobile app? Yes if device is mobile OR viewport is small
export function shouldUseMobileApp(): boolean {
  return isMobileDevice() || isMobileViewport()
}
