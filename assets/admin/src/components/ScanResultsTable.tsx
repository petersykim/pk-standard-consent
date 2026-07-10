import { Badge } from "@pk/ui";
import type { ScanRow } from "../api/scan";

const CATEGORY_BADGE: Record<string, "success" | "accent" | "warning" | "danger" | "muted"> = {
  necessary: "success",
  preferences: "accent",
  analytics: "warning",
  marketing: "danger",
};

type Props = {
  rows: ScanRow[];
  onOverride: (cookieName: string, category: string) => void;
};

export function ScanResultsTable({ rows, onOverride }: Props) {
  return (
    <table className="data-table">
      <thead>
        <tr>
          <th>Cookie name</th>
          <th>Provider</th>
          <th>Category</th>
          <th>Duration</th>
          <th>Source</th>
          <th>First seen</th>
          <th className="col-status">Status</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((row, i) => (
          <ScanResultRow key={i} row={row} onOverride={onOverride} />
        ))}
      </tbody>
    </table>
  );
}

function ScanResultRow({ row, onOverride }: { row: ScanRow; onOverride: (cookieName: string, category: string) => void }) {
  const isViolation = row.compliance_flag === 1;
  const isUncategorized = row.category === "" || row.category === "uncategorized";
  const needsSelect = isViolation || isUncategorized;
  const rowStyle = isViolation ? { background: "var(--pk-danger-soft-bg, hsl(var(--pk-danger-soft)))" } : undefined;

  return (
    <tr style={rowStyle}>
      <td>
        <span className="data-table__primary mono">{row.cookie_name}</span>
        {isViolation && <div className="data-table__secondary" style={{ color: "hsl(var(--pk-danger))" }}>Set before consent was recorded</div>}
        {!isViolation && isUncategorized && <div className="data-table__secondary">Unmapped — assign a category</div>}
      </td>
      <td className="text-secondary">{row.provider}</td>
      <td>
        {needsSelect ? (
          <select
            className="select select--inline"
            aria-label="Override category"
            value={row.category_override ?? ""}
            onChange={(e) => onOverride(row.cookie_name, e.target.value)}
          >
            <option value="">-- assign --</option>
            <option value="necessary">necessary</option>
            <option value="preferences">preferences</option>
            <option value="analytics">analytics</option>
            <option value="marketing">marketing</option>
          </select>
        ) : (
          <Badge variant={CATEGORY_BADGE[row.category] ?? "muted"}>{row.category}</Badge>
        )}
      </td>
      <td className="muted">{row.duration}</td>
      <td><Badge variant="muted">{row.source}</Badge></td>
      <td className="muted" style={{ fontSize: "var(--fs-xs)" }}>{row.first_seen}</td>
      <td>
        {isViolation
          ? <span className="badge badge--violation">Violation</span>
          : isUncategorized
            ? <span className="pill-status pill-status--paused">Needs review</span>
            : <span className="pill-status pill-status--active">OK</span>
        }
      </td>
    </tr>
  );
}
