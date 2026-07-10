import { Badge, IconButton } from "@pk/ui";
import type { LogRow } from "../api/log";

type Props = { rows: LogRow[]; onEraseVisitor: (row: LogRow) => void };

export function LogTable({ rows, onEraseVisitor }: Props) {
  return (
    <table className="data-table data-table--striped">
      <thead>
        <tr>
          <th>Visitor ID</th>
          <th>Region</th>
          <th className="col-status" style={{ textAlign: "center" }}>Preferences</th>
          <th className="col-status" style={{ textAlign: "center" }}>Analytics</th>
          <th className="col-status" style={{ textAlign: "center" }}>Marketing</th>
          <th>Method</th>
          <th>Policy ver.</th>
          <th className="col-date">Recorded</th>
          <th className="col-actions" style={{ textAlign: "right" }}>Actions</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((row) => (
          <tr key={row.id}>
            <td className="mono" style={{ fontSize: "var(--fs-xs)", color: "hsl(var(--pk-muted))" }}>
              {row.visitor_hash}
            </td>
            <td><Badge variant="muted">{row.region}</Badge></td>
            <td style={{ textAlign: "center" }}><BoolCell value={row.preferences} /></td>
            <td style={{ textAlign: "center" }}><BoolCell value={row.analytics} /></td>
            <td style={{ textAlign: "center" }}><BoolCell value={row.marketing} /></td>
            <td><Badge variant="muted">{row.consent_method}</Badge></td>
            <td className="muted" style={{ fontSize: "var(--fs-xs)" }}>{row.policy_ver}</td>
            <td className="muted" style={{ fontSize: "var(--fs-xs)" }}>{row.consented_at}</td>
            <td style={{ textAlign: "right" }}>
              <IconButton variant="danger" aria-label="Erase visitor" onClick={() => onEraseVisitor(row)}>
                <EraseIcon />
              </IconButton>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function BoolCell({ value }: { value: string }) {
  const yes = value === "1" || value === "true";
  return yes
    ? <span className="bool-yes">Yes</span>
    : <span className="bool-no">No</span>;
}

function EraseIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ width: 14, height: 14, stroke: "currentColor" }} aria-hidden="true">
      <polyline points="3 6 5 6 21 6" />
      <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
      <path d="M10 11v6" />
      <path d="M14 11v6" />
      <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
    </svg>
  );
}
