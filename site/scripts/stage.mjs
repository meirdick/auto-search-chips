// Stage one assets folder for the auto-search-chips Worker:
// landing page (site/public) at /, user docs (docs-site/dist) at /docs/.
import { cpSync, existsSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const site = join(dirname(fileURLToPath(import.meta.url)), "..");
const pub = join(site, "public");
const docs = join(site, "..", "docs-site", "dist");
const out = join(site, ".deploy");

if (!existsSync(join(docs, "index.html"))) {
  console.error("Failed: docs-site/dist is missing. Run npm run build:docs first.");
  process.exit(1);
}

rmSync(out, { recursive: true, force: true });
cpSync(pub, out, { recursive: true });
cpSync(docs, join(out, "docs"), { recursive: true });

// Docs ship their own _headers. Workers reads only the root one, so merge.
const docsHeaders = join(out, "docs", "_headers");
const extra = readFileSync(docsHeaders, "utf8").replace(/^\/_astro\/\*/m, "/docs/_astro/*");
rmSync(docsHeaders);

// The landing CSP blocks the docs' inline theme script and search. Drop it under /docs.
const detach = "\n# Docs: the landing CSP blocks inline scripts and search.\n/docs/*\n  ! Content-Security-Policy\n";
writeFileSync(join(out, "_headers"), readFileSync(join(pub, "_headers"), "utf8") + detach + "\n" + extra);

console.log("Staged site/.deploy: landing at /, docs at /docs/");
