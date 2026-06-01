import { useEffect, useState } from 'react'
import { createHighlighterCore, type HighlighterCore } from 'shiki/core'
import { createOnigurumaEngine } from 'shiki/engine/oniguruma'
import githubDark from 'shiki/themes/github-dark.mjs'
import bash from 'shiki/langs/bash.mjs'
import php from 'shiki/langs/php.mjs'
import json from 'shiki/langs/json.mjs'

// A single highlighter shared by every code block, restricted to the few
// languages the docs actually use so only those grammars ship in the bundle.
let highlighterPromise: Promise<HighlighterCore> | null = null

function getHighlighter() {
  highlighterPromise ??= createHighlighterCore({
    themes: [githubDark],
    langs: [bash, php, json],
    engine: createOnigurumaEngine(() => import('shiki/wasm')),
  })
  return highlighterPromise
}

const SUPPORTED = new Set(['bash', 'php', 'json'])

interface CodeBlockProps {
  code: string
  lang?: string
}

export function CodeBlock({ code, lang = 'bash' }: CodeBlockProps) {
  const trimmed = code.trim()
  const highlightable = SUPPORTED.has(lang)
  const [html, setHtml] = useState<string>('')

  useEffect(() => {
    if (!highlightable) return
    let active = true
    getHighlighter()
      .then((hl) => hl.codeToHtml(trimmed, { lang, theme: 'github-dark' }))
      .then((out) => {
        if (active) setHtml(out)
      })
      .catch(() => {
        if (active) setHtml('')
      })
    return () => {
      active = false
    }
  }, [trimmed, lang, highlightable])

  if (!highlightable || !html) {
    return (
      <pre className="overflow-x-auto rounded-xl border border-white/10 bg-slate-900 p-4 text-sm leading-relaxed text-slate-200">
        <code>{trimmed}</code>
      </pre>
    )
  }

  return <div dangerouslySetInnerHTML={{ __html: html }} />
}
