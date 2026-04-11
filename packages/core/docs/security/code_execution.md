# Code execution security model (Chantier E)

This document describes the security model of the `code_execute` tool and
the reasoning behind the sidecar sandbox architecture. Read this before
enabling `synapse.code_executor.enabled: true` in a production setting.

## Threat model

The code executed via `code_execute` comes from an LLM. Even with a
cooperative LLM, it can be:

1. **Accidentally destructive** — the LLM writes a `while True:` loop, a
   `requests.get()` to a random URL, a `shutil.rmtree('/')`, or a
   `pickle.loads()` on untrusted input.
2. **Jailbroken** — a carefully crafted user prompt convinces the LLM to
   execute arbitrary code to extract secrets from the host environment.
3. **Poisoned via retrieved context** — a RAG source or a previous
   agent's output contains hidden instructions that steer the LLM
   toward malicious code.

The security model must assume all three, because there's no reliable
way to distinguish them at runtime.

## Isolation layers

We rely on **defense in depth**. No single mechanism is sufficient; each
layer adds a ceiling the attacker must break.

### Layer 1 — Dedicated container

The sandbox is a **separate Docker container** (`synapse-sandbox`) that
shares no code, no volumes, and no namespace with the main `basile-brain`
container. An attacker who escapes the Python interpreter lands inside
this container, not on the host or inside the app.

The container image is minimal: `python:3.12-slim` + one `server.py` file
that uses only the Python standard library. No pip install, no shell
utility, no compiler.

### Layer 2 — Network isolation

The sandbox container is attached only to `basile-network` (the internal
Docker bridge). **No port is published on the host.** The only service
that can reach it is `basile-brain`. The sandbox cannot reach:

- the host filesystem (no volume mount),
- the internet (no route outside the bridge),
- any service on the host network.

If you change `docker-compose.yml` to publish a port (`"8000:8000"`),
**you break this layer**.

### Layer 3 — Unprivileged user + kernel capabilities

The container runs as `uid 1001 (sandbox)`, not root. Docker-level
restrictions in `docker-compose.yml`:

```yaml
security_opt:
  - no-new-privileges       # can't setuid
cap_drop:
  - ALL                     # zero Linux capabilities
read_only: true             # root filesystem is immutable
tmpfs:
  - /tmp:size=64m           # only writable location, 64 MB cap
```

### Layer 4 — Resource limits

- `mem_limit: 256m` — OOM kill beyond this (kernel-enforced cgroup).
- `cpus: 0.5` — half a CPU maximum (cgroup).
- Python `resource.setrlimit(RLIMIT_AS, 256MB)` inside the subprocess as
  a belt-and-suspenders against memory bombs that would defeat the cgroup
  (rare).

### Layer 5 — Per-execution timeout

Every call to `subprocess.run()` inside `server.py` has a wall-clock
`timeout` (default 10s, max 60s). When the timeout fires, the subprocess
is SIGKILLed and the client receives `error_type: TimeoutException`.

### Layer 6 — Output size cap

`stdout` and `stderr` are truncated to 1 MB each before being sent back
to the caller. Without this, a `while True: print('x' * 1024)` would
saturate the sandbox server's memory before the timeout.

## What is NOT protected

Be explicit about the residual risks:

- **Data exfiltration via stdout.** The LLM can write arbitrary strings
  to stdout, which are returned to the caller (another LLM call) and
  eventually to the user. If your host environment contains secrets that
  the **user** shouldn't see, and the user can influence the prompt,
  they could theoretically read those secrets if they leak into the
  sandbox container's environment variables. **The sandbox container has
  zero environment variables** — empty env passed through docker-compose.
- **Denial of service on the sandbox container itself.** An infinite loop
  will be killed after the timeout, but while it runs, no other
  execution can use that CPU. For a single-user personal instance like
  basile, this is fine. For multi-tenant, add a queue with per-user rate
  limits.
- **Zero-day kernel escape.** If there's a Linux kernel vulnerability
  that lets a process in a container escape to the host, this sandbox
  doesn't protect against it. If that's in your threat model, use
  Firecracker microVMs instead.

## Operational notes

### Activating the executor

1. Make sure the sandbox container is running:
   ```
   cd basile && docker compose up -d --build synapse-sandbox
   docker exec basile_app curl http://synapse-sandbox:8000/health
   # {"status":"ok"}
   ```
2. Enable the flag in `basile/config/packages/synapse.yaml`:
   ```yaml
   synapse:
     code_executor:
       enabled: true
   ```
3. Clear the cache: `docker exec basile_app php bin/console cache:clear`.

Now any agent with `code_execute` in its `allowedToolNames` (or no
restrictions) will get a real `HttpCodeExecutor` backend instead of
`NullCodeExecutor`.

### Changing backends

The backend is selected via an alias in
`SynapseCoreExtension::load()`. To swap `HttpCodeExecutor` for
`E2BCodeExecutor`, `DockerCodeExecutor`, etc., add a new class
implementing `CodeExecutorInterface`, register it in `core.yaml`, and
change the alias override logic.

### Monitoring

- `docker logs basile_sandbox -f` streams execution logs (silenced by
  default, only HTTP errors appear).
- Every execution persists a `SynapseDebugLog` entry on the main app
  side (via `CodeExecuteTool`), so you have an audit trail without
  touching the sandbox.
