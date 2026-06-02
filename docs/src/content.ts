export interface NavItem {
  id: string
  title: string
}

export const nav: NavItem[] = [
  { id: 'overview', title: 'Overview' },
  { id: 'installation', title: 'Installation' },
  { id: 'audit-bundle', title: 'AuditBundle' },
  { id: 'architecture', title: 'Architecture' },
  { id: 'release', title: 'Release & distribution' },
  { id: 'contributing', title: 'Contributing' },
]

export interface Bundle {
  name: string
  package: string
  repo: string
  description: string
  status: 'available' | 'in-development'
}

export const bundles: Bundle[] = [
  {
    name: 'AuditBundle',
    package: 'opus125/audit-bundle',
    repo: 'https://github.com/opus-125/audit-bundle',
    description:
      'Tamper-evident audit trail with GDPR crypto-shredding and retention for Symfony.',
    status: 'available',
  },
]
