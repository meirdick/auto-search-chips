// The live demo of auto-search-chips.
//   Browser -> this Worker -> Durable Object -> container (Laravel workbench app)
//   Container -> http://clairic.gateway -> Clairic entrypoint -> Workers AI binding
// The container has no Internet access and holds no token.
import { DurableObject, WorkerEntrypoint } from "cloudflare:workers";

interface Env {
	AI: Ai;
	DEMO: DurableObjectNamespace<Demo>;
	LIMITER: RateLimit;
	VERSION: WorkerVersionMetadata;
}

const PORT = 8080;
const IDLE_MS = 10 * 60 * 1000;
// Clairic costs about $0.0001 a run. This cap keeps a day under about $0.30.
const SEARCHES_PER_DAY = 3000;
const ORIGINS = ["https://meirdick.com", "https://auto-search-chips.mdick85.workers.dev"];

/** Answers the container's Clairic requests with the Workers AI binding. */
export class Clairic extends WorkerEntrypoint<Env> {
	async fetch(request: Request): Promise<Response> {
		if (request.method !== "POST") return new Response("Method Not Allowed", { status: 405 });
		try {
			const body = (await request.json()) as Record<string, unknown>;
			const result = await this.env.AI.run("@cf/cloudflare/clairic" as never, body as never);
			return Response.json({ success: true, result });
		} catch (error) {
			console.error("clairic failed", error);
			return Response.json({ success: false, errors: [{ message: String(error) }] }, { status: 502 });
		}
	}
}

export class Demo extends DurableObject<Env> {
	private starting: Promise<void> | undefined;

	/** path: "search" with a sentence, or "apply" with an edited chip set as JSON. */
	async ask(path: "search" | "apply", value: string): Promise<Response> {
		const day = new Date().toISOString().slice(0, 10);
		const used = (await this.ctx.storage.get<number>(`searches:${day}`)) ?? 0;
		if (used >= SEARCHES_PER_DAY) {
			return Response.json({ error: "The demo reached its limit for today. Try the saved examples." }, { status: 429 });
		}
		await this.ctx.storage.put(`searches:${day}`, used + 1);

		// A deploy can bring a new image. A running container keeps its old one, so replace it.
		if ((await this.ctx.storage.get<string>("version")) !== this.env.VERSION.id) {
			if (this.ctx.container!.running) await this.ctx.container!.destroy();
			await this.ctx.storage.put("version", this.env.VERSION.id);
		}

		this.starting ??= this.start().finally(() => {
			this.starting = undefined;
		});
		await this.starting;

		const url = `http://container/api/${path}?${path === "search" ? "q" : "state"}=${encodeURIComponent(value)}`;
		const response = await this.ctx.container!.getTcpPort(PORT).fetch(url, { signal: AbortSignal.timeout(20_000) });
		return new Response(response.body, { status: response.status, headers: { "content-type": "application/json" } });
	}

	private async start(): Promise<void> {
		const container = this.ctx.container!;
		if (!container.running) {
			await container.interceptOutboundHttp("clairic.gateway", this.ctx.exports.Clairic);
			container.start({ image: container.images.base, instance: "basic", enableInternet: false });
		}
		await container.setInactivityTimeout(IDLE_MS);

		const port = container.getTcpPort(PORT);
		let last: unknown;
		for (let attempt = 0; attempt < 150; attempt++) {
			try {
				const response = await port.fetch("http://container/up", { signal: AbortSignal.timeout(1000) });
				await response.body?.cancel();
				if (response.ok) return;
				last = new Error(`health check returned ${response.status}`);
			} catch (error) {
				last = error;
			}
			await scheduler.wait(200);
		}
		throw new Error("The container did not start", { cause: last });
	}
}

function cors(request: Request): Record<string, string> {
	const origin = request.headers.get("origin") ?? "";
	const allowed = ORIGINS.includes(origin) || /^http:\/\/(localhost|127\.0\.0\.1):\d+$/.test(origin);
	return allowed ? { "access-control-allow-origin": origin, vary: "origin" } : {};
}

export default {
	async fetch(request: Request, env: Env): Promise<Response> {
		const url = new URL(request.url);
		const headers = cors(request);

		if (request.method === "OPTIONS") {
			return new Response(null, { status: 204, headers: { ...headers, "access-control-allow-methods": "GET, POST", "access-control-allow-headers": "content-type", "access-control-max-age": "86400" } });
		}
		let path: "search" | "apply";
		let value: string;
		if (url.pathname === "/search" && request.method === "GET") {
			path = "search";
			value = (url.searchParams.get("q") ?? "").trim();
			if (value === "" || value.length > 200) {
				return Response.json({ error: "Send a sentence of 1 to 200 characters in q." }, { status: 422, headers });
			}
		} else if (url.pathname === "/apply" && request.method === "POST") {
			path = "apply";
			value = await request.text();
			try {
				if (value.length > 4000) throw new Error("too long");
				JSON.parse(value);
			} catch {
				return Response.json({ error: "Send a chip set as JSON, at most 4000 characters." }, { status: 422, headers });
			}
		} else {
			return Response.json({ error: "Use GET /search?q=<sentence> or POST /apply." }, { status: 404, headers });
		}

		const ip = request.headers.get("cf-connecting-ip") ?? "unknown";
		const { success } = await env.LIMITER.limit({ key: ip });
		if (!success) {
			return Response.json({ error: "Too many searches. Wait a minute." }, { status: 429, headers });
		}

		try {
			const response = await env.DEMO.getByName("demo").ask(path, value);
			return new Response(response.body, { status: response.status, headers: { ...headers, "content-type": "application/json", "cache-control": "no-store" } });
		} catch (error) {
			console.error("search failed", error);
			return Response.json({ error: "The demo app did not answer. Try again in a few seconds." }, { status: 503, headers });
		}
	},
} satisfies ExportedHandler<Env>;
