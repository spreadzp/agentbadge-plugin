#!/usr/bin/env node
// EPIC-262 (SLICE-262-18): fake intel backend for plugin e2e.
// Zero-dep Node http server — the REAL contract (JCS + Ed25519
// signed job bodies + signed keyset doc) plus a /__seed control
// surface that injects exactly the failure modes ADR-INTEL-002
// demands: expired job, bad signature, unknown probe, foreign
// site, revoked consent (claim 403), stale lease (result 409),
// entitlement denial. Deterministic keys — reruns are identical.
//
// Usage: node fake-backend.mjs --port 0
//   stdout: FAKE_PORT=<port> FAKE_ROOT_PUB=<b64> (source for
//   AGENTBADGE_ROOT_PUBKEY in the harness).
import http from "node:http";
import { generateKeyPairSync, sign, createPrivateKey, createPublicKey } from "node:crypto";

// ─── JCS (RFC 8785 subset) — must match Agentbadge_Jcs::encode ────
// ASCII keys: UTF-16BE order == codepoint order == default sort.
function jcs(v) {
  if (v === null) return "null";
  if (typeof v === "boolean" || typeof v === "number") return JSON.stringify(v);
  if (typeof v === "string") return JSON.stringify(v);
  if (Array.isArray(v)) return "[" + v.map(jcs).join(",") + "]";
  const keys = Object.keys(v).sort();
  return "{" + keys.map((k) => JSON.stringify(k) + ":" + jcs(v[k])).join(",") + "}";
}

// ─── Ed25519 from deterministic seed ────────────────────────────
const PKCS8 = Buffer.from([0x30,0x2e,0x02,0x01,0x00,0x30,0x05,0x06,0x03,0x2b,0x65,0x70,0x04,0x22,0x04,0x20]);
const SPKI  = Buffer.from([0x30,0x2a,0x30,0x05,0x06,0x03,0x2b,0x65,0x70,0x03,0x21,0x00]);
function keypair(seedStr) {
  const seed = Buffer.alloc(32, 0);
  Buffer.from(seedStr).copy(seed);
  const privDer = Buffer.concat([PKCS8, seed]);
  const priv = createPrivateKey({ key: privDer, format: "der", type: "pkcs8" });
  const pubDer = createPublicKey(priv).export({ format: "der", type: "spki" });
  return { priv, pubRaw: pubDer.subarray(pubDer.length - 32) };
}
const signB64 = (msg, kp) => sign(null, Buffer.from(msg, "utf8"), kp.priv).toString("base64");

const root = keypair("agentbadge-fake-root-seed");
const jobs = keypair("agentbadge-fake-jobs-seed");
const rogue = keypair("agentbadge-rogue-seed"); // for bad-key scenarios

const ROOT_ID = "fake-root-1";
const JOBS_KEY_ID = "fake-jobs-1";

// ─── state ──────────────────────────────────────────────────────
const nowIso = () => new Date().toISOString();
let state;
const reset = () => {
  state = {
    // site_key → site_id (handshake auth)
    sites: new Map([["absk_fakesite0000000000000000000001", "site_fake1"]]),
    jobs: new Map(),       // job_id → job record
    calls: [],             // call log: {method, path, job?}
    scenarios: {},         // {claim:"consent_denied", result:"conflict", peek:"500"}
    counter: 0,
  };
};
reset();

let seq = 0;
const uid = (p) => `${p}${(++seq).toString().padStart(22, "0")}FAKE`;

function wireJob(seed) {
  const id = `pjob_${uid("")}`;
  const body = {
    job_id: id,
    site_id: seed.site_id ?? "site_fake1",
    probe_id: seed.probe_id ?? "const_read",
    params: seed.params ?? { name: "WP_DEBUG" },
    exp: seed.exp ?? new Date(Date.now() + 3600e3).toISOString(),
    nonce: seed.nonce ?? `n_${uid("")}`,
    key_id: seed.key_id ?? JOBS_KEY_ID,
  };
  const badSig = seed.sig === "bad";
  const kp = seed.sig === "rogue" ? rogue : jobs;
  const sig = {
    algorithm: "ed25519",
    value: badSig ? Buffer.alloc(64, 0xbb).toString("base64") : signB64(jcs(body), kp),
  };
  return { ...body, sig, status: "queued", lease_generation: 0, _claimScenario: seed.claimScenario, _resultScenario: seed.resultScenario, _leak: seed.leak === true };
}

function keysetDoc() {
  const body = {
    root_id: ROOT_ID,
    keysets: [
      {
        key_id: JOBS_KEY_ID,
        purpose: "jobs",
        status: "active",
        not_before: new Date(Date.now() - 3600e3).toISOString(),
        not_after: new Date(Date.now() + 30 * 86400e3).toISOString(),
        public_key: jobs.pubRaw.toString("base64"),
      },
    ],
  };
  return {
    ...body,
    keys: [{ key_id: ROOT_ID, public_key: root.pubRaw.toString("base64") }],
    sig: { algorithm: "ed25519", value: signB64(jcs(body), root) },
  };
}

const auth = (req) => {
  const h = req.headers.authorization ?? "";
  const token = h.startsWith("Bearer ") ? h.slice(7) : "";
  return state.sites.get(token) ?? null;
};

const json = (res, code, obj) => {
  const s = JSON.stringify(obj);
  res.writeHead(code, { "content-type": "application/json" });
  res.end(s);
};

const readBody = (req) =>
  new Promise((r) => {
    let b = "";
    req.on("data", (c) => (b += c));
    req.on("end", () => { try { r(JSON.parse(b || "{}")); } catch { r({}); } });
  });

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, "http://x");
  const path = url.pathname;
  const log = (job) => state.calls.push({ method: req.method, path, job });

  // ── control surface (no auth — local harness only) ──
  if (path === "/__seed" && req.method === "POST") {
    const b = await readBody(req);
    for (const j of b.jobs ?? []) {
      const w = wireJob(j);
      state.jobs.set(w.job_id, w);
    }
    if (b.scenarios) state.scenarios = { ...state.scenarios, ...b.scenarios };
    if (b.sites) for (const s of b.sites) state.sites.set(s.site_key, s.site_id);
    return json(res, 200, { ok: true, jobs: [...state.jobs.values()].map((j) => j.job_id) });
  }
  if (path === "/__reset" && req.method === "POST") { reset(); return json(res, 200, { ok: true }); }
  if (path === "/__calls" && req.method === "GET") return json(res, 200, { calls: state.calls });
  if (path === "/__state" && req.method === "GET") {
    return json(res, 200, { jobs: [...state.jobs.values()].map((j) => ({ job_id: j.job_id, status: j.status, lease_generation: j.lease_generation })) });
  }

  // ── well-known keyset ──
  if (path === "/.well-known/agentbadge-keys.json") {
    log();
    return json(res, 200, keysetDoc());
  }

  // ── handshake ──
  if (path === "/api/intel/ownership/handshake" && req.method === "POST") {
    log();
    const site = auth(req);
    if (!site) return json(res, 401, { error: "key_invalid" });
    return json(res, 200, {
      proof: { site_id: site, method: "plugin_handshake", verified_at: nowIso() },
      consent: { site_id: site, kind: "audit", status: "granted" },
    });
  }

  // ── evidence intake ──
  if (path === "/api/intel/evidence" && req.method === "POST") {
    log();
    const site = auth(req);
    if (!site) return json(res, 401, { error: "site_key_invalid" });
    const b = await readBody(req);
    return json(res, 201, {
      deduplicated: false,
      envelope: {
        envelope_id: `env_${uid("")}`,
        site_id: site,
        schema_version: "1.0",
        received_at: nowIso(),
        submission_echo: b,
      },
    });
  }

  // ── pull jobs ──
  if (path === "/api/intel/jobs" && req.method === "GET") {
    log();
    const site = auth(req);
    if (!site) return json(res, 401, { error: "site_key_invalid" });
    const siteParam = url.searchParams.get("site");
    if (siteParam && siteParam !== site) return json(res, 403, { error: "site_mismatch" });
    if (state.scenarios.peek === "500") return json(res, 500, { error: "backend_error" });
    const jobs = [...state.jobs.values()].filter((j) => j.status === "queued" && (j.site_id === site || j._leak))
      .map(({ _claimScenario, _resultScenario, _leak, ...w }) => w);
    return json(res, 200, { jobs });
  }

  const mClaim = path.match(/^\/api\/intel\/jobs\/([^/]+)\/claim$/);
  if (mClaim && req.method === "POST") {
    const job = state.jobs.get(mClaim[1]);
    log(job?.job_id);
    const site = auth(req);
    if (!site) return json(res, 401, { error: "site_key_invalid" });
    if (!job || job.site_id !== site) return json(res, 404, { error: "missing" });
    if (job._claimScenario === "consent_denied" || state.scenarios.claim === "consent_denied")
      return json(res, 403, { error: "consent_denied" });
    if (job.status !== "queued") return json(res, 409, { error: "not_queued" });
    job.status = "claimed";
    job.lease_generation += 1;
    job._leaseToken = `lease_${job.lease_generation}_${uid("")}`;
    return json(res, 200, {
      job_id: job.job_id,
      status: job.status,
      lease_token: job._leaseToken,
      lease_generation: job.lease_generation,
      lease_expires_at: new Date(Date.now() + 900e3).toISOString(),
      retry: false,
    });
  }

  const mResult = path.match(/^\/api\/intel\/jobs\/([^/]+)\/result$/);
  if (mResult && req.method === "POST") {
    const job = state.jobs.get(mResult[1]);
    log(job?.job_id);
    const site = auth(req);
    if (!site) return json(res, 401, { error: "site_key_invalid" });
    const b = await readBody(req);
    if (!job || job.site_id !== site) return json(res, 404, { error: "missing" });
    if (job._resultScenario === "conflict" || state.scenarios.result === "conflict")
      return json(res, 409, { error: "result_conflict" });
    if (b.lease_token !== job._leaseToken)
      return json(res, 409, { error: "lease_expired" });
    job.status = "done";
    return json(res, 201, {
      job_id: job.job_id, status: "done",
      envelope_id: `env_${uid("")}`, deduplicated: false,
    });
  }

  json(res, 404, { error: "not_found" });
});

const port = Number(process.argv[process.argv.indexOf("--port") + 1] ?? 0) || 0;
server.listen(port, "127.0.0.1", () => {
  const p = server.address().port;
  console.log(`FAKE_PORT=${p}`);
  console.log(`FAKE_ROOT_PUB=${root.pubRaw.toString("base64")}`);
});
