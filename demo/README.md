# Live demo

The workbench app of this package in a Cloudflare Container, behind a Worker.

- URL: `https://auto-search-chips-demo.mdick85.workers.dev`
- `GET /search?q=<sentence>`: how the package reads a sentence, and the first rows.
- `POST /apply` with `{chips, leftover, sort}` as JSON: the same answer for an edited chip set.

How it is built:

- `Dockerfile`: PHP 8.4, the package, the workbench app and a seeded SQLite file. The build context is the repository root.
- `src/index.ts`: the Worker. It checks the request, limits the rate (20 a minute for each visitor, 3,000 a day in total) and forwards it to the container.
- The container has no Internet access and no API token. It calls `http://clairic.gateway`, which the Worker answers with the Workers AI binding.

Deploy (needs Docker):

```bash
cd demo
npm install
npm run deploy
```

A deploy starts a new container on the next search. The first search then takes about 15 seconds.
