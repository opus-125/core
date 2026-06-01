import { CodeBlock } from './components/CodeBlock.tsx'
import { bundles, nav } from './content.ts'

function Section({
  id,
  title,
  children,
}: {
  id: string
  title: string
  children: React.ReactNode
}) {
  return (
    <section id={id} className="scroll-mt-20 border-t border-white/5 py-12 first:border-t-0">
      <h2 className="mb-6 text-2xl font-semibold tracking-tight text-white">{title}</h2>
      <div className="space-y-4 text-slate-300 leading-relaxed">{children}</div>
    </section>
  )
}

function Code({ children }: { children: string }) {
  return (
    <code className="rounded bg-white/10 px-1.5 py-0.5 font-mono text-[0.85em] text-sky-300">
      {children}
    </code>
  )
}

export function App() {
  return (
    <div className="mx-auto flex min-h-screen max-w-6xl gap-10 px-6">
      {/* Sidebar */}
      <aside className="sticky top-0 hidden h-screen w-56 shrink-0 flex-col py-10 lg:flex">
        <a href="#overview" className="mb-8 flex items-center gap-2 text-lg font-bold text-white">
          <span className="grid h-8 w-8 place-items-center rounded-lg bg-sky-500/20 text-sky-300">
            ◆
          </span>
          Opus
        </a>
        <nav className="space-y-1 text-sm">
          {nav.map((item) => (
            <a
              key={item.id}
              href={`#${item.id}`}
              className="block rounded-md px-3 py-1.5 text-slate-400 transition hover:bg-white/5 hover:text-white"
            >
              {item.title}
            </a>
          ))}
        </nav>
        <div className="mt-auto pt-6 text-xs text-slate-600">
          <a href="https://github.com/opus-125/core" className="hover:text-slate-400">
            opus-125/core ↗
          </a>
        </div>
      </aside>

      {/* Content */}
      <main className="min-w-0 flex-1 py-10">
        <header className="border-b border-white/5 pb-12">
          <p className="mb-3 text-sm font-medium uppercase tracking-widest text-sky-400">
            Symfony 8 · PHP 8.4
          </p>
          <h1 className="text-4xl font-bold tracking-tight text-white sm:text-5xl">
            Opus bundles
          </h1>
          <p className="mt-4 max-w-2xl text-lg text-slate-400">
            A family of Symfony bundles developed in a single monorepo, split into individual
            read-only packages and published to Packagist.
          </p>
        </header>

        <Section id="overview" title="Overview">
          <p>
            All Opus bundles are developed together in the{' '}
            <a className="text-sky-400 hover:underline" href="https://github.com/opus-125/core">
              opus-125/core
            </a>{' '}
            monorepo, exercised by a shared demo application, and split automatically into their
            own read-only repositories for distribution.
          </p>
          <div className="mt-4 overflow-hidden rounded-xl border border-white/10">
            <table className="w-full text-left text-sm">
              <thead className="bg-white/5 text-slate-400">
                <tr>
                  <th className="px-4 py-3 font-medium">Bundle</th>
                  <th className="px-4 py-3 font-medium">Package</th>
                  <th className="px-4 py-3 font-medium">Status</th>
                </tr>
              </thead>
              <tbody>
                {bundles.map((b) => (
                  <tr key={b.package} className="border-t border-white/5">
                    <td className="px-4 py-3">
                      <a className="font-medium text-sky-400 hover:underline" href={b.repo}>
                        {b.name}
                      </a>
                      <div className="text-xs text-slate-500">{b.description}</div>
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-slate-300">{b.package}</td>
                    <td className="px-4 py-3">
                      <span className="rounded-full bg-amber-500/15 px-2.5 py-0.5 text-xs text-amber-300">
                        {b.status === 'available' ? 'available' : 'in development'}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Section>

        <Section id="installation" title="Installation">
          <p>Install any bundle through Composer:</p>
          <CodeBlock lang="bash" code="composer require opus/audit-bundle" />
          <p>
            With Symfony Flex the bundle registers itself. Otherwise enable it in{' '}
            <Code>config/bundles.php</Code>:
          </p>
          <CodeBlock
            lang="php"
            code={`return [
    // ...
    Opus\\AuditBundle\\OpusAuditBundle::class => ['all' => true],
];`}
          />
        </Section>

        <Section id="audit-bundle" title="AuditBundle">
          <p>
            <Code>opus/audit-bundle</Code> is a tamper-evident audit trail with GDPR-compliant
            crypto-shredding and retention — the shared compliance spine for public-sector software.
            Behaviour is declared on entities with attributes; configuration is infrastructure only.
          </p>
          <p>
            The <strong>Spine</strong> (default-on) records every create/update/delete of an{' '}
            <Code>#[Auditable]</Code> entity through a Doctrine listener into a linear SHA-256
            hash-chain with gapless, per-stream sequence numbers — so any later tampering is
            detectable by <Code>audit:verify</Code>. <Code>#[Sensitive]</Code> fields are encrypted
            per data subject; erasing a person (GDPR Art. 17) destroys their key, leaving the
            ciphertext — and therefore the chain — intact while the personal content becomes
            permanently unreadable. <Code>#[Retention]</Code> bounds how long entries are kept.
          </p>
          <p>
            Escalation tiers — versioning (E1), Merkle integrity (E2), qualified signatures (E3,
            eIDAS / ID Austria) and trigger-level capture (E4) — are opt-in and independent; you pay
            for none of them until you switch them on. See{' '}
            <Code>packages/audit-bundle/docs/index.md</Code> for the full guide.
          </p>
          <p>
            Source &amp; issues:{' '}
            <a
              className="text-sky-400 hover:underline"
              href="https://github.com/opus-125/audit-bundle"
            >
              opus-125/audit-bundle
            </a>
            .
          </p>
        </Section>

        <Section id="architecture" title="Architecture">
          <p>The monorepo is organised as follows:</p>
          <CodeBlock
            lang="text"
            code={`packages/            One directory per bundle (a standalone Composer package)
  audit-bundle/      opus/audit-bundle -> split to opus-125/audit-bundle
demo/                Symfony 8 app integrating the bundles via a path repository
docs/                This documentation site (Vite + React)
.github/workflows/
  ci.yml             Tests, PHPStan, coding standards, monorepo validation
  split.yml          Read-only split of each package to its own repo
  sync-docs.yml      Mirror docs/ to the opus-125.github.io Pages repo`}
          />
        </Section>

        <Section id="release" title="Release & distribution">
          <p>
            Pushing to <Code>main</Code> mirrors each package into its read-only repository; pushing
            a tag mirrors the tag so Packagist publishes a new release.
          </p>
          <CodeBlock
            lang="bash"
            code={`vendor/bin/monorepo-builder release v0.1.0 --dry-run   # preview
vendor/bin/monorepo-builder release v0.1.0             # tag + bump
git push origin main --tags`}
          />
        </Section>

        <Section id="contributing" title="Contributing">
          <p>
            Bundles are read-only mirrors — open issues and pull requests against the{' '}
            <a className="text-sky-400 hover:underline" href="https://github.com/opus-125/core">
              monorepo
            </a>
            , not the split repositories. Run the full check suite locally with:
          </p>
          <CodeBlock lang="bash" code="composer check" />
        </Section>

        <footer className="border-t border-white/5 py-8 text-sm text-slate-600">
          Built with Vite &amp; React · MIT License
        </footer>
      </main>
    </div>
  )
}
