import { useState, useContext } from "react";
import { PageHeader, Panel, Field, Input, Textarea, Switch, EmptyState, Spinner, Badge, useToast } from "@pk/ui";
import { useScanResults, useScanStatus, useScanConfig, runScan, saveOverride, saveScanConfig } from "../api/scan";
import type { ScanRow, ScanConfig, ScanStatus } from "../api/scan";
import { ScanResultsTable } from "../components/ScanResultsTable";
import { ConfigContext } from "../ConfigContext";
import type { AppConfig } from "../config";

function useScanConfigDraft(config: AppConfig) {
  const { data: remote, loading, error } = useScanConfig(config);
  const [draft, setDraft] = useState<ScanConfig | null>(null);
  const [saving, setSaving] = useState(false);
  const { toast } = useToast();
  const data = draft ?? remote;

  const patch = (updates: Partial<ScanConfig>) => {
    if (data == null) return;
    setDraft({ ...data, ...updates });
  };

  const discard = () => setDraft(null);

  const handleSave = async () => {
    if (data == null) return;
    setSaving(true);
    try {
      await saveScanConfig(config, data);
      setDraft(null);
      toast({ title: "Scan settings saved", variant: "success" });
    } catch {
      toast({ title: "Save failed", variant: "danger" });
    } finally {
      setSaving(false);
    }
  };

  return { data, loading, error, draft, saving, patch, discard, handleSave };
}

function useScanActions(config: AppConfig) {
  const [scanning, setScanning] = useState(false);
  const [overrides, setOverrides] = useState<Record<string, string>>({});
  const [savingOverrides, setSavingOverrides] = useState(false);
  const { toast } = useToast();

  const handleRunScan = async () => {
    setScanning(true);
    try {
      await runScan(config);
      toast({ title: "Scan complete", variant: "success" });
      window.location.reload();
    } catch {
      toast({ title: "Scan failed", variant: "danger" });
      setScanning(false);
    }
  };

  const handleOverride = (cookieName: string, category: string) => {
    setOverrides((prev) => ({ ...prev, [cookieName]: category }));
  };

  const handleSaveOverrides = async () => {
    setSavingOverrides(true);
    try {
      await Promise.all(Object.entries(overrides).map(([name, cat]) => saveOverride(config, name, cat)));
      toast({ title: "Overrides saved", variant: "success" });
      setOverrides({});
    } catch {
      toast({ title: "Save failed", variant: "danger" });
    } finally {
      setSavingOverrides(false);
    }
  };

  return { scanning, overrides, savingOverrides, handleRunScan, handleOverride, handleSaveOverrides };
}

export function CookieScan() {
  const config = useContext(ConfigContext);
  const { status, loading: statusLoading } = useScanStatus(config);
  const { data: results, loading: resultsLoading } = useScanResults(config);
  const scanConfigState = useScanConfigDraft(config);
  const { scanning, overrides, savingOverrides, handleRunScan, handleOverride, handleSaveOverrides } = useScanActions(config);

  const hasResults = results != null && results.length > 0;
  const scanBlocked = status?.status === "blocked";
  const scanCompleted = status?.status === "done" || status?.status === "partial";
  const violations = results?.filter((r) => r.compliance_flag === 1).length ?? 0;
  const uncategorized = results?.filter((r) => r.category === "" || r.category === "uncategorized").length ?? 0;
  const mergedResults: ScanRow[] = results?.map((r) =>
    overrides[r.cookie_name] != null ? { ...r, category_override: overrides[r.cookie_name] ?? null } : r
  ) ?? [];

  if (statusLoading || resultsLoading) return <div className="page-body"><Spinner /></div>;

  return (
    <>
      <PageHeader
        title="Cookie Scan"
        description="Crawl your site to discover cookies set before and after consent. Results populate the cookie table in the preference center."
        actions={<ScanActions scanning={scanning} status={status ?? undefined} onRun={handleRunScan} />}
      />
      <div className="page-body stack stack--lg">
        <ScanConfigPanel {...scanConfigState} />
        {scanning ? <ScanProgressCard /> : scanBlocked ? (
          <Panel padded={false}><div className="card__body">
            <EmptyState icon={<ScanIcon />} title="Scan couldn’t reach your site" description="The crawler got an authentication wall (Cloudflare Access, basic-auth, or an SSO login) instead of your pages, so it couldn’t see any cookies. This is common on staging/protected sites. Results here don’t mean your site is clean — run the scan from an environment the crawler can reach without a login." action={<button className="btn btn--primary" type="button" onClick={handleRunScan}><ScanIcon /> Run scan again</button>} />
          </div></Panel>
        ) : hasResults ? (
          <ScanResults results={mergedResults} violations={violations} uncategorized={uncategorized} onOverride={handleOverride} onSave={handleSaveOverrides} saving={savingOverrides} hasOverrides={Object.keys(overrides).length > 0} />
        ) : scanCompleted ? (
          <Panel padded={false}><div className="card__body">
            <EmptyState icon={<ScanIcon />} title="No cookies detected" description="Your last scan found no cookies in scope. Run another scan after adding analytics or marketing tags." action={<button className="btn btn--primary" type="button" onClick={handleRunScan}><ScanIcon /> Run scan</button>} />
          </div></Panel>
        ) : (
          <Panel padded={false}><div className="card__body">
            <EmptyState icon={<ScanIcon />} title="No scan results yet" description="Run a scan to discover which cookies your site sets and when. The scan crawls your front page and a sample of your content URLs, then reports what it finds." action={<button className="btn btn--primary" type="button" onClick={handleRunScan}><ScanIcon /> Run first scan</button>} />
          </div></Panel>
        )}
      </div>
    </>
  );
}

type ScanConfigPanelProps = ReturnType<typeof useScanConfigDraft>;


function ScanConfigPanel({ data, loading, error, draft, saving, patch, discard, handleSave }: ScanConfigPanelProps) {
  if (loading) return <Panel title="Scan configuration"><Spinner /></Panel>;
  if (error != null || data == null) return null;

  return (
    <Panel title="Scan configuration">
      <Field label="URLs to scan" htmlFor="scan-urls" description="One URL per line. Leave blank to scan only the home page. Inner pages like /checkout or /blog often set marketing cookies the home page doesn't.">
        <Textarea id="scan-urls" rows={4} value={data.scan_urls} onChange={(e) => patch({ scan_urls: e.target.value })} placeholder="https://example.com/checkout&#10;https://example.com/blog" />
      </Field>
      <div className="grid-2">
        <Field label="URL limit" htmlFor="scan-url-limit" description="Maximum number of URLs to crawl per scan." className="field--narrow">
          <Input type="number" id="scan-url-limit" value={String(data.scan_url_limit)} onChange={(e) => patch({ scan_url_limit: parseInt(e.target.value, 10) })} min="1" max="100" />
        </Field>
        <Field label="Request timeout (seconds)" htmlFor="scan-request-timeout" description="Per-URL timeout before the crawler gives up." className="field--narrow">
          <Input type="number" id="scan-request-timeout" value={String(data.scan_request_timeout)} onChange={(e) => patch({ scan_request_timeout: parseInt(e.target.value, 10) })} min="5" max="120" />
        </Field>
        <Field label="Total wall time limit (seconds)" htmlFor="scan-wall-limit" description="Maximum total duration for a single scan run." className="field--narrow">
          <Input type="number" id="scan-wall-limit" value={String(data.scan_wall_limit)} onChange={(e) => patch({ scan_wall_limit: parseInt(e.target.value, 10) })} min="10" max="600" />
        </Field>
      </div>
      <div className="row row--between" style={{ marginTop: "var(--sp-4)" }}>
        <div>
          <div className="switch-row__label">Verify SSL certificates</div>
          <div className="field__hint" style={{ marginTop: "var(--sp-1)" }}>Turn off only for local/staging with self-signed certs.</div>
        </div>
        <Switch checked={data.scan_sslverify} onCheckedChange={(v) => patch({ scan_sslverify: v })} aria-label="Verify SSL certificates" />
      </div>
      <div className="row row--end" style={{ marginTop: "var(--sp-4)" }}>
        <button className="btn btn--ghost" type="button" disabled={draft == null} onClick={discard}>Discard</button>
        <button className="btn btn--primary" type="button" disabled={saving} onClick={handleSave}>{saving ? "Saving…" : "Save scan settings"}</button>
      </div>
    </Panel>
  );
}

function ScanActions({ scanning, status, onRun }: { scanning: boolean; status?: ScanStatus | undefined; onRun: () => void }) {
  const showSummary = !scanning && (status?.status === "done" || status?.status === "partial");
  return (
    <>
      {showSummary && status?.count != null && (
        <span className="muted" style={{ fontSize: "var(--fs-xs)" }}>
          Last scan: {status.count} cookie{status.count !== 1 ? "s" : ""}{status.flagged != null ? `, ${status.flagged} flagged` : ""}
        </span>
      )}
      <button className="btn btn--primary" type="button" disabled={scanning} onClick={onRun} title={scanning ? "Scan runs server-side and cannot be interrupted once started" : undefined}>
        <ScanIcon /> {scanning ? "Scanning…" : "Run scan"}
      </button>
    </>
  );
}

function ScanProgressCard() {
  return (
    <div className="scan-progress-card" aria-label="Scan in progress">
      <div className="empty-state__icon"><ScanIcon /></div>
      <p style={{ fontSize: "var(--fs-md)", fontWeight: "var(--fw-semibold)" }}>Scanning your site…</p>
      <p className="muted" style={{ fontSize: "var(--fs-sm)" }}>Crawling front page and sample URLs. This takes 20–60 seconds.</p>
      <p className="muted" style={{ fontSize: "var(--fs-xs)" }}>Scan runs server-side and cannot be interrupted once started.</p>
      <Spinner />
    </div>
  );
}

type ScanResultsProps = { results: ScanRow[]; violations: number; uncategorized: number; onOverride: (name: string, cat: string) => void; onSave: () => void; saving: boolean; hasOverrides: boolean };

function ScanResults({ results, violations, uncategorized, onOverride, onSave, saving, hasOverrides }: ScanResultsProps) {
  const total = results.length;
  const categorized = total - uncategorized;
  return (
    <>
      <div className="meta-grid">
        <MetaCell label="Total cookies found" value={String(total)} />
        <MetaCell label="Categorized" value={String(categorized)} variant="success" />
        <MetaCell label="Uncategorized" value={String(uncategorized)} variant="accent" />
        <MetaCell label="Violations" value={String(violations)} variant="danger" />
      </div>
      <div className="card">
        <div className="card__header">
          <div><div className="card__title">Scan results</div><div className="card__subtitle">Cookies set before consent are flagged as violations — they must be necessary or removed.</div></div>
          <div className="row row--gap-2">
            {violations > 0 && <Badge variant="danger">{violations} violation{violations !== 1 ? "s" : ""}</Badge>}
            {uncategorized > 0 && <Badge variant="accent">{uncategorized} need category</Badge>}
          </div>
        </div>
        <ScanResultsTable rows={results} onOverride={onOverride} />
        <div className="card__footer" style={{ justifyContent: "flex-start", gap: "var(--sp-4)" }}>
          <span className="muted" style={{ fontSize: "var(--fs-xs)" }}>Source legend:</span>
          <span style={{ fontSize: "var(--fs-xs)" }}><Badge variant="muted">registry</Badge> Known cookie from built-in provider map</span>
          <span style={{ fontSize: "var(--fs-xs)" }}><Badge variant="accent">signature</Badge> Detected by name pattern — verify manually</span>
          <span style={{ fontSize: "var(--fs-xs)" }}><Badge variant="muted">server-set</Badge> Set by server before JS consent layer ran</span>
        </div>
      </div>
      {hasOverrides && (
        <div className="row row--end">
          <button className="btn btn--ghost" type="button" onClick={() => window.location.reload()}>Discard changes</button>
          <button className="btn btn--primary" type="button" disabled={saving} onClick={onSave}>{saving ? "Saving…" : "Save category overrides"}</button>
        </div>
      )}
    </>
  );
}

function MetaCell({ label, value, variant }: { label: string; value: string; variant?: string | undefined }) {
  const cls = variant != null ? `meta-grid__value--${variant}` : "";
  return (
    <div className="meta-grid__cell">
      <div className="meta-grid__label">{label}</div>
      <div className={`meta-grid__value ${cls}`}>{value}</div>
    </div>
  );
}

function ScanIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="11" cy="11" r="7" /><path d="m21 21-4.3-4.3" />
    </svg>
  );
}
