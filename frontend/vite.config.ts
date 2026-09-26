import { execSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// The version shown on the login screen (2026-09-26), e.g. "1.0.214"
// and "27/09/2026" -- worked out as deploy/version.sh does (VERSION's
// major.minor, then the number of commits; the commit's date in
// Singapore time), the same pair Central Command's Client Upgrades
// shows for this server. A server build gets them from
// deploy/version.sh through Docker build args; a local build or
// `npm run dev` asks git. Blank when neither can say.
function git(args: string): string {
  try {
    return execSync(`git ${args}`, { stdio: ['ignore', 'pipe', 'ignore'], env: { ...process.env, TZ: 'Asia/Singapore' } })
      .toString()
      .trim()
  } catch {
    return ''
  }
}
function localVersion(): string {
  const count = git('rev-list --count HEAD')
  if (!count) return ''
  let base = '1.0'
  try {
    base = readFileSync(new URL('../VERSION', import.meta.url), 'utf8').trim() || base
  } catch {
    // no VERSION file beside the frontend -- keep 1.0
  }
  return `${base}.${count}`
}
const appVersion = process.env.APP_VERSION || localVersion()
const appVersionDate = process.env.APP_VERSION_DATE || git('log -1 --format=%cd --date=format-local:%d/%m/%Y')

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  define: {
    __APP_VERSION__: JSON.stringify(appVersion),
    __APP_VERSION_DATE__: JSON.stringify(appVersionDate),
  },
  server: {
    proxy: {
      // API_TARGET lets the self-test (selftest/run.sh) point a second
      // copy of the app at its own throwaway backend, beside the usual one.
      '/api': {
        target: process.env.API_TARGET ?? 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
