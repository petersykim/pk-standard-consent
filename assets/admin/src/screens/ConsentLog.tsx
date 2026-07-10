import { useState, useContext } from "react";
import { PageHeader, Spinner, Badge, Field, Input, ConfirmDialog, useToast } from "@pk/ui";
import { useLogData, useLogStats, eraseVisitor } from "../api/log";
import type { LogStats, LogRow } from "../api/log";
import type { AppConfig } from "../config";
import { LogTable } from "../components/LogTable";
import { Pager } from "../components/Pager";
import { ConfigContext } from "../ConfigContext";

// Must equal LogRest::PER_PAGE_DEFAULT (50) and is sent explicitly as per_page so the two
// can't drift — the pager label read "Showing 1–25" while the server returned 50 rows (F3).
const PER_PAGE = 50;

export function ConsentLog() {
  const config = useContext(ConfigContext);
  const [page, setPage] = useState(1);
  const [filterRegion, setFilterRegion] = useState("");
  const [filterVersion, setFilterVersion] = useState("");
  const { data, loading, error, reload } = useLogData(config, page, PER_PAGE, filterRegion, filterVersion);
  const { stats } = useLogStats(config);
  const [eraseTarget, setEraseTarget] = useState<LogRow | null>(null);

  const handleFilterRegion = (val: string) => { setFilterRegion(val); setPage(1); };
  const handleFilterVersion = (val: string) => { setFilterVersion(val); setPage(1); };

  if (loading) return <div className="page-body"><Spinner /></div>;
  if (error != null || data == null) return <div className="page-body"><p className="muted">{error ?? "Failed to load"}</p></div>;

  const exportUrl = `${config.restUrl}log/export?_wpnonce=${config.nonce}`;

  return (
    <>
      <PageHeader
        title="Consent Log"
        description="Read-only audit trail of every consent record. Visitor IDs are anonymized hashes — no PII is stored. Export for compliance audits."
        actions={
          <a className="btn btn--secondary" href={exportUrl} download>
            <DownloadIcon /> Export CSV
          </a>
        }
      />
      <div className="page-body stack stack--lg">
        <LogStatsGrid stats={stats} />
        <div className="card">
          <div className="card__header">
            <div className="card__title">Consent records</div>
            <div className="row row--gap-2">
              <Badge variant="muted">{data.total} total</Badge>
            </div>
          </div>
          <div className="card__body" style={{ borderBottom: "1px solid hsl(var(--pk-border))", paddingBottom: "var(--sp-4)" }}>
            <div className="row row--gap-4">
              <Field label="Filter by region" htmlFor="log-filter-region">
                <Input id="log-filter-region" value={filterRegion} onChange={(e) => handleFilterRegion(e.target.value)} placeholder="e.g. EU" style={{ width: "10rem" }} />
              </Field>
              <Field label="Filter by policy version" htmlFor="log-filter-version">
                <Input id="log-filter-version" value={filterVersion} onChange={(e) => handleFilterVersion(e.target.value)} placeholder="e.g. 2024-03-01" style={{ width: "12rem" }} />
              </Field>
            </div>
          </div>
          <LogTable rows={data.rows} onEraseVisitor={setEraseTarget} />
          <Pager current={page} total={data.pages} totalRecords={data.total} perPage={PER_PAGE} onChange={setPage} />
        </div>
      </div>
      <EraseVisitorDialog config={config} target={eraseTarget} onClose={() => setEraseTarget(null)} onErased={reload} />
    </>
  );
}

function EraseVisitorDialog({
  config,
  target,
  onClose,
  onErased,
}: {
  config: AppConfig;
  target: LogRow | null;
  onClose: () => void;
  onErased: () => void;
}) {
  const { toast } = useToast();

  const handleConfirm = async () => {
    if (target == null) return;
    onClose();
    try {
      const result = await eraseVisitor(config, target.id);
      onErased();
      toast({ title: `Erased ${result.deleted} record${result.deleted === 1 ? "" : "s"} for this visitor`, variant: "success" });
    } catch {
      toast({ title: "Erase failed", description: "The record may already be gone.", variant: "danger" });
    }
  };

  return (
    <ConfirmDialog
      open={target != null}
      title="Erase visitor"
      description="Permanently deletes every consent record for this visitor. This cannot be undone."
      confirmLabel="Erase visitor"
      destructive
      onConfirm={handleConfirm}
      onCancel={onClose}
    />
  );
}

function LogStatsGrid({ stats }: { stats: LogStats | null }) {
  if (stats == null) {
    return <div className="meta-grid"><Spinner /></div>;
  }
  return (
    <div className="meta-grid">
      <MetaCell label="Total records" value={stats.total.toLocaleString()} />
      <MetaCell label="Last 30 days" value={stats.last30.toLocaleString()} variant="accent" />
      <MetaCell label="Accept-all rate" value={`${stats.acceptAllPct}%`} variant="success" />
      <MetaCell label="Reject-all rate" value={`${stats.rejectAllPct}%`} />
      <MetaCell label="Custom preferences" value={`${stats.customPct}%`} />
      <MetaCell label="Retention" value={`${stats.retentionDays} days`} />
    </div>
  );
}

function MetaCell({ label, value, variant }: { label: string; value: string; variant?: string }) {
  const cls = variant != null ? `meta-grid__value--${variant}` : "";
  return (
    <div className="meta-grid__cell">
      <div className="meta-grid__label">{label}</div>
      <div className={`meta-grid__value ${cls}`}>{value}</div>
    </div>
  );
}

function DownloadIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
      <polyline points="7 10 12 15 17 10" />
      <line x1="12" y1="15" x2="12" y2="3" />
    </svg>
  );
}
